<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A timed-out MySQL DDL can commit tables before Laravel records the migration.
        // The recovery migration that follows safely completes any missing columns.
        if (Schema::hasTable('personnel_types')) {
            return;
        }
        Schema::create('personnel_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('personnel_type_id')->nullable()->after('department_id')
                ->constrained('personnel_types')->nullOnDelete();
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
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
            $table->string('required_evidence_type')->nullable();
            $table->boolean('is_carry_forward')->default(false);
            $table->decimal('max_carry_forward', 7, 2)->default(0);
            $table->decimal('max_accumulated', 7, 2)->nullable();
            $table->enum('period_basis', ['fiscal_year', 'calendar_year'])->default('fiscal_year');
            $table->json('eligibility_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['personnel_type_id', 'leave_type_id', 'effective_from'], 'leave_policy_lookup');
        });

        Schema::create('leave_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['prefix', 'year']);
        });

        Schema::create('leave_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leaves')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evidence_type', 80);
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->date('issued_date')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
            $table->index(['leave_id', 'evidence_type']);
        });

        Schema::create('leave_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['leave_id', 'created_at']);
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('leave_policy_id')->nullable()->after('leave_type_id')
                ->constrained('leave_policies')->nullOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->after('leave_policy_id')
                ->constrained('fiscal_years')->nullOnDelete();
            $table->decimal('reserved_days', 7, 2)->default(0)->after('total_days');
            $table->timestamp('notified_supervisor_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->softDeletes();
            $table->unique('document_number');
        });

        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->unsignedInteger('step_order')->nullable();
            $table->string('step_name')->nullable();
            $table->foreignId('required_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('delegated_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('reserved', 7, 2)->default(0)->after('used');
            $table->foreignId('leave_policy_id')->nullable()->constrained('leave_policies')->nullOnDelete();
            $table->unique(['user_id', 'leave_type_id', 'year'], 'leave_balance_unique_period');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropUnique('leave_balance_unique_period');
            $table->dropConstrainedForeignId('leave_policy_id');
            $table->dropColumn('reserved');
        });
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('required_role_id');
            $table->dropConstrainedForeignId('delegated_from_user_id');
            $table->dropColumn(['step_order', 'step_name', 'acted_at']);
        });
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropUnique(['document_number']);
            $table->dropConstrainedForeignId('leave_policy_id');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['reserved_days', 'notified_supervisor_at', 'submitted_at', 'voided_at', 'void_reason', 'deleted_at']);
        });
        Schema::dropIfExists('leave_audit_logs');
        Schema::dropIfExists('leave_evidences');
        Schema::dropIfExists('leave_document_sequences');
        Schema::dropIfExists('leave_policies');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('personnel_type_id'));
        Schema::dropIfExists('personnel_types');
    }
};
