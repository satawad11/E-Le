<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — ปฏิทิน ปีงบประมาณ วันหยุด และตารางเวลาทำงาน (TIME-01/02)
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->integer('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['date'], 'holidays_date_unique');
        });

        Schema::create('work_calendar_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->enum('day_type', ['holiday', 'working_day']);
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['date', 'org_unit_id'], 'work_calendar_exceptions_date_org_unit_id_unique');
            $table->foreign(['org_unit_id'], 'work_calendar_exceptions_org_unit_id_foreign')->references(['id'])->on('org_units')->restrictOnDelete();
        });

        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('code', 80);
            $table->string('name', 255);
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'work_schedules_code_unique');
            $table->index(['is_default', 'is_active'], 'work_schedules_is_default_is_active_index');
            $table->unique(['uuid'], 'work_schedules_uuid_unique');
        });

        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_schedule_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('crosses_midnight')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['work_schedule_id', 'day_of_week'], 'work_schedule_day_unique');
            $table->foreign(['work_schedule_id'], 'work_schedule_days_work_schedule_id_foreign')->references(['id'])->on('work_schedules')->cascadeOnDelete();
        });

        Schema::create('work_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('work_schedule_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['uuid'], 'work_schedule_assignments_uuid_unique');
            $table->index(['org_unit_id', 'effective_from', 'effective_to'], 'work_schedule_org_effective');
            $table->index(['user_id', 'effective_from', 'effective_to'], 'work_schedule_user_effective');
            $table->foreign(['created_by'], 'work_schedule_assignments_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['org_unit_id'], 'work_schedule_assignments_org_unit_id_foreign')->references(['id'])->on('org_units')->cascadeOnDelete();
            $table->foreign(['user_id'], 'work_schedule_assignments_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            $table->foreign(['work_schedule_id'], 'work_schedule_assignments_work_schedule_id_foreign')->references(['id'])->on('work_schedules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_assignments');
        Schema::dropIfExists('work_schedule_days');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('work_calendar_exceptions');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('fiscal_years');
    }
};
