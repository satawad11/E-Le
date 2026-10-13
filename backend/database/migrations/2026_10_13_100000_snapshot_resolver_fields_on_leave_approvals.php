<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ขั้นตอนอนุมัติที่ pin ไว้กับใบลา (leave_approvals) เก็บแค่ required_role_id
 *
 * เมื่อ APPROVAL-01 เพิ่มผู้อนุมัติแบบ DEPARTMENT_HEAD / DIRECTOR / SPECIFIC_USER ฯลฯ
 * ใน approval_steps แล้ว snapshot ไม่ได้เก็บชนิดผู้อนุมัติมาด้วย
 * ApproverResolverService จึงตีความทุกขั้นเป็น ROLE และหา role_id ไม่เจอ
 * → ไม่มีใครอนุมัติขั้นนั้นได้ (ยกเว้นผู้ดูแลระบบ)
 *
 * additive เท่านั้น · backfill จาก approval_steps ลำดับเดียวกันให้ใบที่ยังรออนุมัติ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_approvals', 'approver_type')) {
                $table->string('approver_type')->nullable()->after('required_role_id');
            }
            if (! Schema::hasColumn('leave_approvals', 'specific_user_id')) {
                $table->unsignedBigInteger('specific_user_id')->nullable()->after('approver_type');
            }
            if (! Schema::hasColumn('leave_approvals', 'permission_name')) {
                $table->string('permission_name')->nullable()->after('specific_user_id');
            }
        });

        $steps = DB::table('approval_steps')->get(['step_order', 'approver_type', 'specific_user_id', 'permission_name']);
        foreach ($steps as $step) {
            DB::table('leave_approvals')
                ->where('step_order', $step->step_order)
                ->where('status', 'Pending')
                ->whereNull('approver_type')
                ->whereIn('leave_id', DB::table('leaves')->where('status', 'Pending')->select('id'))
                ->update([
                    'approver_type' => $step->approver_type ?? 'ROLE',
                    'specific_user_id' => $step->specific_user_id,
                    'permission_name' => $step->permission_name,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            foreach (['permission_name', 'specific_user_id', 'approver_type'] as $column) {
                if (Schema::hasColumn('leave_approvals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
