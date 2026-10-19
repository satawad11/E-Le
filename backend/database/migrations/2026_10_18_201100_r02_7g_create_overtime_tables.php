<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — ปฏิบัติงานล่วงเวลา (OT-01)
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ot_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('order_number', 255)->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->string('timezone', 64)->default('Asia/Bangkok');
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->timestamp('approved_start')->nullable();
            $table->timestamp('approved_end')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['order_number'], 'ot_orders_order_number_unique');
            $table->index(['org_unit_id', 'status'], 'ot_orders_org_unit_id_status_index');
            $table->index(['scheduled_start', 'scheduled_end'], 'ot_orders_scheduled_start_scheduled_end_index');
            $table->unique(['uuid'], 'ot_orders_uuid_unique');
            $table->foreign(['created_by'], 'ot_orders_created_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['org_unit_id'], 'ot_orders_org_unit_id_foreign')->references(['id'])->on('org_units')->nullOnDelete();
        });

        Schema::create('ot_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('ot_order_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->string('status', 30)->default('assigned');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['ot_order_id', 'user_id'], 'ot_assignments_ot_order_id_user_id_unique');
            $table->index(['user_id', 'status'], 'ot_assignments_user_id_status_index');
            $table->unique(['uuid'], 'ot_assignments_uuid_unique');
            $table->foreign(['assigned_by'], 'ot_assignments_assigned_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['ot_order_id'], 'ot_assignments_ot_order_id_foreign')->references(['id'])->on('ot_orders')->restrictOnDelete();
            $table->foreign(['user_id'], 'ot_assignments_user_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });

        Schema::create('ot_time_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('ot_assignment_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();
            $table->timestamp('certified_start')->nullable();
            $table->timestamp('certified_end')->nullable();
            $table->unsignedInteger('certified_minutes')->nullable();
            $table->string('status', 30)->default('not_started');
            $table->string('source', 30)->default('web');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->string('reconciliation_status', 30)->default('pending');
            $table->json('reconciliation_details')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('legacy_attendance_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['actual_start', 'actual_end'], 'ot_time_records_actual_start_actual_end_index');
            $table->unique(['legacy_attendance_id'], 'ot_time_records_legacy_attendance_id_unique');
            $table->unique(['ot_assignment_id'], 'ot_time_records_ot_assignment_id_unique');
            $table->index(['user_id', 'status'], 'ot_time_records_user_id_status_index');
            $table->unique(['uuid'], 'ot_time_records_uuid_unique');
            $table->foreign(['attendance_id'], 'ot_time_records_attendance_id_foreign')->references(['id'])->on('attendances')->nullOnDelete();
            $table->foreign(['certified_by'], 'ot_time_records_certified_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['ot_assignment_id'], 'ot_time_records_ot_assignment_id_foreign')->references(['id'])->on('ot_assignments')->restrictOnDelete();
            $table->foreign(['user_id'], 'ot_time_records_user_id_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ot_time_records');
        Schema::dropIfExists('ot_assignments');
        Schema::dropIfExists('ot_orders');
    }
};
