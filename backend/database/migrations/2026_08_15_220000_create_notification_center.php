<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 120)->unique();
            $table->string('module', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('default_channels');
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_type_id')->constrained('notification_types')->restrictOnDelete();
            $table->string('channel', 30);
            $table->unsignedInteger('version');
            $table->string('locale', 10)->default('th');
            $table->string('subject')->nullable();
            $table->text('body_template');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['notification_type_id', 'channel', 'version', 'locale'], 'notification_template_version_unique');
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_type_id')->constrained('notification_types')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('queued');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['related_type', 'related_id']);
            $table->index(['status', 'occurred_at']);
        });

        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['notification_event_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $table->foreignId('notification_template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('channel', 30);
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['notification_recipient_id', 'channel']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('notification_type_id')->nullable()->constrained('notification_types')->cascadeOnDelete();
            $table->string('channel', 30);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'notification_type_id', 'channel'], 'notification_preference_lookup');
        });

        $this->seedTypesAndTemplates();
    }

    private function seedTypesAndTemplates(): void
    {
        $types = [
            'leave.submitted' => ['leave', 'ส่งคำขอลา', 'ส่งคำขอลาเข้าสู่ระบบแล้ว'],
            'leave.approval_requested' => ['leave', 'มีใบลารออนุมัติ', 'มีรายการลารอการตรวจสอบหรืออนุมัติ'],
            'leave.approved' => ['leave', 'ใบลาได้รับอนุมัติ', 'คำขอลาของคุณได้รับอนุมัติแล้ว'],
            'leave.rejected' => ['leave', 'ใบลาไม่ได้รับอนุมัติ', 'คำขอลาของคุณไม่ได้รับอนุมัติ กรุณาตรวจสอบในระบบ'],
            'leave.returned' => ['leave', 'ใบลาถูกส่งกลับ', 'คำขอลาของคุณถูกส่งกลับเพื่อแก้ไข'],
            'attendance.correction' => ['attendance', 'คำขอแก้ไขเวลาปฏิบัติงาน', 'สถานะคำขอแก้ไขเวลาปฏิบัติงานมีการเปลี่ยนแปลง'],
            'attendance.late' => ['attendance', 'แจ้งเตือนการมาสาย', 'มีรายการลงเวลาที่ต้องตรวจสอบ'],
            'attendance.early_leave' => ['attendance', 'แจ้งเตือนออกก่อนเวลา', 'มีรายการลงเวลาที่ต้องตรวจสอบ'],
            'overtime.approval' => ['overtime', 'สถานะคำสั่ง OT', 'สถานะคำสั่งปฏิบัติงานล่วงเวลามีการเปลี่ยนแปลง'],
        ];
        $now = now();

        foreach ($types as $code => [$module, $name, $body]) {
            $typeId = DB::table('notification_types')->insertGetId([
                'uuid' => (string) Str::uuid(), 'code' => $code, 'module' => $module,
                'name' => $name, 'default_channels' => json_encode(['in_app', 'email']),
                'is_mandatory' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (['in_app', 'email', 'line_oa', 'telegram'] as $channel) {
                DB::table('notification_templates')->insert([
                    'uuid' => (string) Str::uuid(), 'notification_type_id' => $typeId,
                    'channel' => $channel, 'version' => 1, 'locale' => 'th',
                    'subject' => $name, 'body_template' => $body,
                    'effective_from' => now()->toDateString(), 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_types');
    }
};
