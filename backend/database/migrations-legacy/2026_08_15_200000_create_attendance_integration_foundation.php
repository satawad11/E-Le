<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('type', 30); // manual | web | device | import
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->json('config')->nullable();
            $table->string('health_state', 30)->default('unknown');
            $table->text('cursor')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'is_active']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('attendance_source_id')->constrained('attendance_sources')->restrictOnDelete();
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['attendance_source_id', 'external_id']);
        });

        Schema::create('attendance_identity_maps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('attendance_source_id')->constrained('attendance_sources')->restrictOnDelete();
            $table->string('external_person_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['attendance_source_id', 'external_person_id'], 'attendance_identity_source_person_unique');
        });

        Schema::create('attendance_raw_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('attendance_source_id')->constrained('attendance_sources')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_person_id')->nullable();
            $table->string('event_type', 30); // check_in | check_out
            $table->timestamp('occurred_at'); // UTC instant
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->string('dedup_key', 191);
            $table->json('payload')->nullable();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->unique(['attendance_source_id', 'dedup_key'], 'attendance_raw_source_dedup_unique');
            $table->index(['user_id', 'occurred_at']);
            $table->index('processed_at');
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('attendance_id')->constrained('attendances')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->json('before_values');
            $table->json('proposed_values');
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_raw_events');
        Schema::dropIfExists('attendance_identity_maps');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('attendance_sources');
    }
};
