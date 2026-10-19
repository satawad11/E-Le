<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — ลงเวลาปฏิบัติงาน (ATT-01/02)
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('code', 100);
            $table->string('name', 255);
            $table->string('type', 30);
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->json('config')->nullable();
            $table->string('health_state', 30)->default('unknown');
            $table->text('cursor')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'attendance_sources_code_unique');
            $table->index(['type', 'is_active'], 'attendance_sources_type_is_active_index');
            $table->unique(['uuid'], 'attendance_sources_uuid_unique');
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('attendance_source_id');
            $table->string('external_id', 255);
            $table->string('name', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['attendance_source_id', 'external_id'], 'devices_attendance_source_id_external_id_unique');
            $table->unique(['uuid'], 'devices_uuid_unique');
            $table->foreign(['attendance_source_id'], 'devices_attendance_source_id_foreign')->references(['id'])->on('attendance_sources')->restrictOnDelete();
            $table->foreign(['org_unit_id'], 'devices_org_unit_id_foreign')->references(['id'])->on('org_units')->nullOnDelete();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->date('date');
            $table->dateTime('check_in_time')->nullable();
            $table->string('check_in_image', 255)->nullable();
            $table->string('check_in_location', 255)->nullable();
            $table->dateTime('check_out_time')->nullable();
            $table->string('check_out_image', 255)->nullable();
            $table->string('check_out_location', 255)->nullable();
            $table->decimal('distance_from_college', 10, 2)->nullable();
            $table->string('status', 255)->default('Present');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->time('ot_check_in_time')->nullable();
            $table->time('ot_check_out_time')->nullable();
            $table->integer('late_minutes')->nullable()->default(0);
            $table->integer('early_leave_minutes')->nullable()->default(0);
            $table->integer('ot_minutes')->nullable()->default(0);
            $table->index(['date'], 'attendances_date_idx');
            $table->index(['user_id', 'date'], 'attendances_user_date_idx');
            $table->unique(['user_id', 'date'], 'attendances_user_id_date_unique');
            $table->unique(['uuid'], 'attendances_uuid_unique');
            $table->foreign(['user_id'], 'attendances_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('attendance_raw_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid');
            $table->unsignedBigInteger('attendance_source_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('external_person_id', 255)->nullable();
            $table->string('event_type', 30);
            $table->timestamp('occurred_at');
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->string('dedup_key', 191);
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['event_uuid'], 'attendance_raw_events_event_uuid_unique');
            $table->index(['processed_at'], 'attendance_raw_events_processed_at_index');
            $table->index(['user_id', 'occurred_at'], 'attendance_raw_events_user_id_occurred_at_index');
            $table->unique(['attendance_source_id', 'dedup_key'], 'attendance_raw_source_dedup_unique');
            $table->foreign(['attendance_id'], 'attendance_raw_events_attendance_id_foreign')->references(['id'])->on('attendances')->nullOnDelete();
            $table->foreign(['attendance_source_id'], 'attendance_raw_events_attendance_source_id_foreign')->references(['id'])->on('attendance_sources')->restrictOnDelete();
            $table->foreign(['device_id'], 'attendance_raw_events_device_id_foreign')->references(['id'])->on('devices')->nullOnDelete();
            $table->foreign(['user_id'], 'attendance_raw_events_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('attendance_identity_maps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('attendance_source_id');
            $table->string('external_person_id', 255);
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['uuid'], 'attendance_identity_maps_uuid_unique');
            $table->unique(['attendance_source_id', 'external_person_id'], 'attendance_identity_source_person_unique');
            $table->foreign(['attendance_source_id'], 'attendance_identity_maps_attendance_source_id_foreign')->references(['id'])->on('attendance_sources')->restrictOnDelete();
            $table->foreign(['user_id'], 'attendance_identity_maps_user_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('requested_by');
            $table->json('before_values');
            $table->json('proposed_values');
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['user_id', 'status'], 'attendance_corrections_user_id_status_index');
            $table->unique(['uuid'], 'attendance_corrections_uuid_unique');
            $table->foreign(['attendance_id'], 'attendance_corrections_attendance_id_foreign')->references(['id'])->on('attendances')->restrictOnDelete();
            $table->foreign(['decided_by'], 'attendance_corrections_decided_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['requested_by'], 'attendance_corrections_requested_by_foreign')->references(['id'])->on('users')->restrictOnDelete();
            $table->foreign(['user_id'], 'attendance_corrections_user_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_identity_maps');
        Schema::dropIfExists('attendance_raw_events');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('attendance_sources');
    }
};
