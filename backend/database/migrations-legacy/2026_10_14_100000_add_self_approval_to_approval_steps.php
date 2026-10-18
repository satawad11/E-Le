<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * อนุญาตให้ผู้อนุมัติของขั้นหนึ่งอนุมัติใบลาของตนเองในขั้นนั้นได้ — ตั้งค่ารายขั้น
 *
 * เจ้าของระบบตัดสิน 2026-10-14 (docs/DECISION-LOG.md · LEAVE-APPROVAL-02):
 * สายอนุมัติ งานบุคลากรตรวจสอบ → รองผู้อำนวยการ → ผู้อำนวยการ และทุกขั้นอนุมัติ
 * ใบลาของตนเองได้ เช่น ผู้อำนวยการอนุมัติขั้นสุดท้ายของใบลาตนเอง
 * รองผู้อำนวยการอนุมัติขั้นที่ 2 ของใบลาตนเอง
 *
 * - approval_steps.allow_self_approval   ค่าตั้งของขั้น (แก้ได้ที่หน้าจัดการลำดับการอนุมัติ)
 * - leave_approvals.allow_self_approval  pin ไว้กับใบลาตอนยื่น เหมือน approver_type
 * - leave_approvals.is_self_approval     บันทึกว่าขั้นนี้ผู้ยื่นอนุมัติเอง
 *
 * additive · เปิดสวิตช์ให้ขั้นที่มีอยู่ทุกขั้นตามมติ และ backfill ใบที่ยังค้างพิจารณา
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('approval_steps', 'allow_self_approval')) {
                $table->boolean('allow_self_approval')->default(false)->after('permission_name');
            }
        });

        Schema::table('leave_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_approvals', 'allow_self_approval')) {
                $table->boolean('allow_self_approval')->default(false)->after('permission_name');
            }
            if (! Schema::hasColumn('leave_approvals', 'is_self_approval')) {
                $table->boolean('is_self_approval')->default(false)->after('delegated_from_user_id');
            }
        });

        DB::table('approval_steps')->update(['allow_self_approval' => true]);

        $openLeaves = DB::table('leaves')
            ->where('status', 'Pending')
            ->orWhere(fn ($q) => $q->where('status', 'Approved')->where('cancellation_status', 'Pending'))
            ->select('id');

        foreach (DB::table('approval_steps')->pluck('step_order') as $stepOrder) {
            DB::table('leave_approvals')
                ->where('step_order', $stepOrder)
                ->whereIn('leave_id', $openLeaves)
                ->update(['allow_self_approval' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            foreach (['is_self_approval', 'allow_self_approval'] as $column) {
                if (Schema::hasColumn('leave_approvals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            if (Schema::hasColumn('approval_steps', 'allow_self_approval')) {
                $table->dropColumn('allow_self_approval');
            }
        });
    }
};
