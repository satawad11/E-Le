<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — Notification Center (NOTIFY-01)
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('code', 120);
            $table->string('module', 50);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('default_channels');
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'notification_types_code_unique');
            $table->unique(['uuid'], 'notification_types_uuid_unique');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('notification_type_id');
            $table->string('channel', 30);
            $table->unsignedInteger('version');
            $table->string('locale', 10)->default('th');
            $table->string('subject', 255)->nullable();
            $table->text('body_template');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['notification_type_id', 'channel', 'version', 'locale'], 'notification_template_version_unique');
            $table->unique(['uuid'], 'notification_templates_uuid_unique');
            $table->foreign(['notification_type_id'], 'notification_templates_notification_type_id_foreign')->references(['id'])->on('notification_types')->restrictOnDelete();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('notification_type_id')->nullable();
            $table->string('channel', 30);
            $table->boolean('enabled')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['user_id', 'notification_type_id', 'channel'], 'notification_preference_lookup');
            $table->foreign(['notification_type_id'], 'notification_preferences_notification_type_id_foreign')->references(['id'])->on('notification_types')->cascadeOnDelete();
            $table->foreign(['user_id'], 'notification_preferences_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('notification_type_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('related_type', 255)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('queued');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['related_type', 'related_id'], 'notification_events_related_type_related_id_index');
            $table->index(['status', 'occurred_at'], 'notification_events_status_occurred_at_index');
            $table->unique(['uuid'], 'notification_events_uuid_unique');
            $table->foreign(['actor_id'], 'notification_events_actor_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['notification_type_id'], 'notification_events_notification_type_id_foreign')->references(['id'])->on('notification_types')->restrictOnDelete();
        });

        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('notification_event_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['notification_event_id', 'user_id'], 'notification_recipients_notification_event_id_user_id_unique');
            $table->index(['user_id', 'read_at'], 'notification_recipients_user_id_read_at_index');
            $table->unique(['uuid'], 'notification_recipients_uuid_unique');
            $table->foreign(['notification_event_id'], 'notification_recipients_notification_event_id_foreign')->references(['id'])->on('notification_events')->cascadeOnDelete();
            $table->foreign(['user_id'], 'notification_recipients_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('notification_recipient_id');
            $table->unsignedBigInteger('notification_template_id')->nullable();
            $table->string('channel', 30);
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['notification_recipient_id', 'channel'], 'notification_deliveries_notification_recipient_id_channel_unique');
            $table->index(['status', 'next_attempt_at'], 'notification_deliveries_status_next_attempt_at_index');
            $table->unique(['uuid'], 'notification_deliveries_uuid_unique');
            $table->foreign(['notification_recipient_id'], 'notification_deliveries_notification_recipient_id_foreign')->references(['id'])->on('notification_recipients')->cascadeOnDelete();
            $table->foreign(['notification_template_id'], 'notification_deliveries_notification_template_id_foreign')->references(['id'])->on('notification_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_types');
    }
};
