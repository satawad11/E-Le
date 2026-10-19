<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R02-7 — ระบบการลา (LEAVE-01..03) — leave_approvals ไม่มี required_role_id
 *
 * สร้างในรูปสุดท้ายของระบบเดิม (รวม alter ทุกไฟล์ใน database/migrations-legacy/)
 * ตามแผน REBUILD-2569 §4.2 "เก็บโครงสร้าง ล้างข้อมูล" · ตัดคอลัมน์/FK ที่อ้างตารางที่ถูกตัด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('code_prefix', 255)->nullable()->comment('Leave code prefix (e.g. L01)');
            $table->string('document_template_code', 255)->nullable();
            $table->json('extra_fields')->nullable();
            $table->text('description')->nullable();
            $table->boolean('counts_toward_promotion')->default(true);
            $table->integer('max_days')->nullable();
            $table->boolean('is_prorated')->default(false);
            $table->boolean('is_carry_forward')->default(false);
            $table->integer('max_carry_forward')->nullable();
            $table->boolean('requires_doc')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('exclude_holidays')->default(true)->comment('If true, holidays and weekends are not counted as leave days');
            $table->integer('notice_period_days')->default(0)->comment('Number of days in advance required to request this leave');
            $table->boolean('allow_past_dates')->default(false)->comment('If true, can submit leave for past dates (e.g. sick leave)');
            $table->integer('require_attachment_after_days')->nullable()->comment('Require attachment if leave exceeds this many days');
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('personnel_type_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('annual_quota', 7, 2)->nullable();
            $table->decimal('max_days_per_request', 7, 2)->nullable();
            $table->decimal('max_days_with_pay', 7, 2)->nullable();
            $table->unsignedInteger('minimum_service_days')->default(0);
            $table->unsignedInteger('notice_period_days')->default(0);
            $table->boolean('allow_past_dates')->default(false);
            $table->boolean('exclude_holidays')->default(true);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('requires_substitute')->default(false);
            $table->decimal('attachment_required_after_days', 7, 2)->nullable();
            $table->string('required_evidence_type', 255)->nullable();
            $table->boolean('is_carry_forward')->default(false);
            $table->decimal('max_carry_forward', 7, 2)->default(0.00);
            $table->decimal('max_accumulated', 7, 2)->nullable();
            $table->enum('period_basis', ['fiscal_year', 'calendar_year'])->default('fiscal_year');
            $table->json('eligibility_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['personnel_type_id', 'leave_type_id', 'effective_from'], 'leave_policy_lookup');
            $table->foreign(['leave_type_id'], 'leave_policies_leave_type_id_foreign')->references(['id'])->on('leave_types')->cascadeOnDelete();
            $table->foreign(['personnel_type_id'], 'leave_policies_personnel_type_id_foreign')->references(['id'])->on('personnel_types')->cascadeOnDelete();
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('document_number', 255)->nullable()->comment('Permanent document number for the leave');
            $table->string('verification_hash', 64)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('leave_policy_id')->nullable();
            $table->unsignedBigInteger('fiscal_year_id')->nullable();
            $table->unsignedBigInteger('document_template_version_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('leave_period', ['full_day', 'morning', 'afternoon'])->default('full_day')->comment('Period of leave: full_day, morning, afternoon');
            $table->decimal('total_days', 5, 2);
            $table->decimal('paid_days', 7, 2)->default(0.00);
            $table->decimal('unpaid_days', 7, 2)->default(0.00);
            $table->decimal('reserved_days', 7, 2)->default(0.00);
            $table->text('reason');
            $table->text('contact_address')->nullable();
            $table->string('attachment', 255)->nullable();
            $table->string('status', 20)->default('Draft');
            $table->integer('approval_level')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('substitute_id')->nullable();
            $table->enum('substitute_status', ['Pending', 'Accepted', 'Rejected'])->nullable();
            $table->text('substitute_reason')->nullable();
            $table->unsignedBigInteger('hr_verified_by')->nullable();
            $table->timestamp('hr_verified_at')->nullable();
            $table->json('meta_data')->nullable();
            $table->json('personnel_snapshot')->nullable();
            $table->json('document_snapshot')->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->enum('cancellation_status', ['None', 'Pending', 'Approved', 'Rejected'])->default('None');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancellation_date')->nullable();
            $table->unsignedInteger('cancellation_approval_level')->nullable();
            $table->timestamp('notified_supervisor_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['document_number'], 'leaves_document_number_unique');
            $table->index(['end_date'], 'leaves_end_date_idx');
            $table->index(['leave_type_id'], 'leaves_leave_type_id_idx');
            $table->index(['start_date'], 'leaves_start_date_index');
            $table->index(['status'], 'leaves_status_index');
            $table->index(['user_id'], 'leaves_user_id_idx');
            $table->index(['user_id', 'status'], 'leaves_user_status_idx');
            $table->unique(['uuid'], 'leaves_uuid_unique');
            $table->unique(['verification_hash'], 'leaves_verification_hash_unique');
            $table->foreign(['document_template_version_id'], 'leaves_document_template_version_id_foreign')->references(['id'])->on('document_template_versions')->nullOnDelete();
            $table->foreign(['fiscal_year_id'], 'leaves_fiscal_year_id_foreign')->references(['id'])->on('fiscal_years')->nullOnDelete();
            $table->foreign(['hr_verified_by'], 'leaves_hr_verified_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['leave_policy_id'], 'leaves_leave_policy_id_foreign')->references(['id'])->on('leave_policies')->nullOnDelete();
            $table->foreign(['leave_type_id'], 'leaves_leave_type_id_foreign')->references(['id'])->on('leave_types')->cascadeOnDelete();
            $table->foreign(['substitute_id'], 'leaves_substitute_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['user_id'], 'leaves_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            $table->foreign(['voided_by'], 'leaves_voided_by_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `leaves` ADD CONSTRAINT `leaves_status_check` CHECK (`status` IN ('Draft', 'Pending', 'Approved', 'Rejected', 'Cancelled', 'Voided'))");
        }

        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->string('stage', 20)->default('request');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->string('approver_name', 255)->nullable();
            $table->string('approver_position', 255)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->integer('level')->default(1);
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->text('comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedInteger('step_order')->nullable();
            $table->string('step_name', 255)->nullable();
            $table->string('approver_type', 255)->nullable();
            $table->unsignedBigInteger('specific_user_id')->nullable();
            $table->string('permission_name', 255)->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->boolean('allow_self_approval')->default(false);
            $table->unsignedBigInteger('delegated_from_user_id')->nullable();
            $table->string('acting_type', 20)->nullable();
            $table->string('acting_position', 255)->nullable();
            $table->string('acting_order_ref', 255)->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->boolean('is_self_approval')->default(false);
            $table->timestamp('acted_at')->nullable();
            $table->index(['leave_id', 'stage', 'step_order'], 'leave_approvals_stage_step_index');
            $table->foreign(['approver_id'], 'leave_approvals_approver_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
            $table->foreign(['delegated_from_user_id'], 'leave_approvals_delegated_from_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['leave_id'], 'leave_approvals_leave_id_foreign')->references(['id'])->on('leaves')->cascadeOnDelete();
            $table->foreign(['org_unit_id'], 'leave_approvals_org_unit_id_foreign')->references(['id'])->on('org_units')->restrictOnDelete();
            $table->foreign(['recorded_by_user_id'], 'leave_approvals_recorded_by_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['specific_user_id'], 'leave_approvals_specific_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->integer('year');
            $table->decimal('quota', 7, 2)->default(0.00);
            $table->double('carried_forward')->default(0);
            $table->decimal('used', 7, 2)->default(0.00);
            $table->decimal('reserved', 7, 2)->default(0.00);
            $table->decimal('carry_forward_days', 7, 2)->default(0.00)->comment('Days accumulated from previous years');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('leave_policy_id')->nullable();
            $table->unique(['user_id', 'leave_type_id', 'year'], 'leave_balance_unique_period');
            $table->unique(['user_id', 'leave_type_id', 'year'], 'leave_balances_user_id_leave_type_id_year_unique');
            $table->foreign(['leave_policy_id'], 'leave_balances_leave_policy_id_foreign')->references(['id'])->on('leave_policies')->nullOnDelete();
            $table->foreign(['leave_type_id'], 'leave_balances_leave_type_id_foreign')->references(['id'])->on('leave_types')->cascadeOnDelete();
            $table->foreign(['user_id'], 'leave_balances_user_id_foreign')->references(['id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('leave_action_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 255);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['leave_id'], 'leave_action_logs_leave_id_foreign')->references(['id'])->on('leaves')->cascadeOnDelete();
            $table->foreign(['user_id'], 'leave_action_logs_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->date('cancel_from');
            $table->date('cancel_to');
            $table->decimal('days', 7, 2);
            $table->text('reason');
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign(['approved_by'], 'leave_adjustments_approved_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['leave_id'], 'leave_adjustments_leave_id_foreign')->references(['id'])->on('leaves')->cascadeOnDelete();
            $table->foreign(['requested_by'], 'leave_adjustments_requested_by_foreign')->references(['id'])->on('users')->restrictOnDelete();
        });

        Schema::create('leave_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 80);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['leave_id', 'created_at'], 'leave_audit_logs_leave_id_created_at_index');
            $table->foreign(['leave_id'], 'leave_audit_logs_leave_id_foreign')->references(['id'])->on('leaves')->nullOnDelete();
            $table->foreign(['user_id'], 'leave_audit_logs_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });

        Schema::create('leave_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['prefix', 'year'], 'leave_document_sequences_prefix_year_unique');
        });

        Schema::create('leave_evidences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('evidence_type', 80);
            $table->string('original_name', 255);
            $table->string('storage_path', 255)->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->date('issued_date')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['leave_id', 'evidence_type'], 'leave_evidences_leave_id_evidence_type_index');
            $table->foreign(['leave_id'], 'leave_evidences_leave_id_foreign')->references(['id'])->on('leaves')->cascadeOnDelete();
            $table->foreign(['uploaded_by'], 'leave_evidences_uploaded_by_foreign')->references(['id'])->on('users')->nullOnDelete();
            $table->foreign(['verified_by'], 'leave_evidences_verified_by_foreign')->references(['id'])->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_evidences');
        Schema::dropIfExists('leave_document_sequences');
        Schema::dropIfExists('leave_audit_logs');
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_action_logs');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_approvals');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_types');
    }
};
