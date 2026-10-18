<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $t) {
            if (! Schema::hasColumn('leaves', 'leave_policy_id')) {
                $t->foreignId('leave_policy_id')->nullable()->constrained('leave_policies')->nullOnDelete();
            }
            if (! Schema::hasColumn('leaves', 'fiscal_year_id')) {
                $t->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            }
            if (! Schema::hasColumn('leaves', 'reserved_days')) {
                $t->decimal('reserved_days', 7, 2)->default(0);
            }
            if (! Schema::hasColumn('leaves', 'notified_supervisor_at')) {
                $t->timestamp('notified_supervisor_at')->nullable();
            }
            if (! Schema::hasColumn('leaves', 'submitted_at')) {
                $t->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('leaves', 'voided_by')) {
                $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('leaves', 'voided_at')) {
                $t->timestamp('voided_at')->nullable();
            }
            if (! Schema::hasColumn('leaves', 'void_reason')) {
                $t->text('void_reason')->nullable();
            }
            if (! Schema::hasColumn('leaves', 'deleted_at')) {
                $t->softDeletes();
            }
        });
        Schema::table('leave_approvals', function (Blueprint $t) {
            if (! Schema::hasColumn('leave_approvals', 'step_order')) {
                $t->unsignedInteger('step_order')->nullable();
            }
            if (! Schema::hasColumn('leave_approvals', 'step_name')) {
                $t->string('step_name')->nullable();
            }
            if (! Schema::hasColumn('leave_approvals', 'required_role_id')) {
                $t->foreignId('required_role_id')->nullable()->constrained('roles')->nullOnDelete();
            }
            if (! Schema::hasColumn('leave_approvals', 'delegated_from_user_id')) {
                $t->foreignId('delegated_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('leave_approvals', 'acted_at')) {
                $t->timestamp('acted_at')->nullable();
            }
        });
        Schema::table('leave_balances', function (Blueprint $t) {
            if (! Schema::hasColumn('leave_balances', 'reserved')) {
                $t->decimal('reserved', 7, 2)->default(0);
            }
            if (! Schema::hasColumn('leave_balances', 'leave_policy_id')) {
                $t->foreignId('leave_policy_id')->nullable()->constrained('leave_policies')->nullOnDelete();
            }
        });
    }

    public function down(): void {}
};
