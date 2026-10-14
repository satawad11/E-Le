<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ให้ "คำขอยกเลิกใบลา" มีสายอนุมัติของตัวเอง แบบเดียวกับการขอลา
 *
 * เดิมคำขอยกเลิกยืมสายอนุมัติของใบลาไปใช้ทั้งดุ้น:
 *   - เขียนทับ `leaves.approval_level` ของใบลาเดิม ทำให้ร่องรอยว่าใบลาผ่านอนุมัติถึงขั้นไหนหายไป
 *   - ไม่มี snapshot ขั้นตอน จึงไม่มีบันทึกว่าใครอนุมัติการยกเลิกในขั้นใด ไม่มีลายเซ็น ไม่มีความเห็น
 *   - เดินตาม `approval_steps` ปัจจุบัน คำขอที่ค้างอยู่จึงเปลี่ยนตามเมื่อผู้ดูแลแก้สายอนุมัติ
 *
 * migration นี้เพิ่มแบบ additive:
 *   - `leave_approvals.stage` แยกแถวของสายขอลา (`request`) ออกจากสายขอยกเลิก (`cancellation`)
 *   - `leaves.cancellation_approval_level` ให้สายยกเลิกเดินระดับของตัวเอง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_approvals', 'stage')) {
                $table->string('stage', 20)->default('request')->after('leave_id');
                $table->index(['leave_id', 'stage', 'step_order'], 'leave_approvals_stage_step_index');
            }
        });

        Schema::table('leaves', function (Blueprint $table) {
            if (! Schema::hasColumn('leaves', 'cancellation_approval_level')) {
                $table->unsignedInteger('cancellation_approval_level')->nullable()->after('cancellation_date');
            }
        });

        // แถวเดิมทั้งหมดคือสายของการขอลา
        DB::table('leave_approvals')->whereNull('stage')->update(['stage' => 'request']);

        if (! Schema::hasTable('approval_steps')) {
            return;
        }

        /*
         * คำขอยกเลิกที่ค้างอยู่ ณ วันอัปเกรด ยังไม่มี snapshot ของตัวเอง
         * สร้างให้จากสายอนุมัติปัจจุบัน และคงระดับที่ค้างอยู่เดิมไว้ เพื่อไม่ให้คำขอย้อนกลับไปเริ่มใหม่
         */
        $steps = DB::table('approval_steps')->orderBy('step_order')->get();

        if ($steps->isEmpty()) {
            return;
        }

        $pending = DB::table('leaves')->where('cancellation_status', 'Pending')->get(['id', 'approval_level']);

        foreach ($pending as $leave) {
            $currentLevel = (int) ($leave->approval_level ?: $steps->first()->step_order);

            DB::table('leaves')->where('id', $leave->id)
                ->update(['cancellation_approval_level' => $currentLevel]);

            foreach ($steps as $step) {
                $exists = DB::table('leave_approvals')
                    ->where('leave_id', $leave->id)
                    ->where('stage', 'cancellation')
                    ->where('step_order', $step->step_order)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('leave_approvals')->insert([
                    'leave_id' => $leave->id,
                    'stage' => 'cancellation',
                    'step_order' => $step->step_order,
                    'step_name' => $step->name,
                    'required_role_id' => $step->role_id,
                    'approver_type' => $step->approver_type ?? 'ROLE',
                    'specific_user_id' => $step->specific_user_id ?? null,
                    'permission_name' => $step->permission_name ?? null,
                    // ขั้นก่อนหน้าระดับปัจจุบันถือว่าผ่านมาแล้วตามพฤติกรรมเดิม
                    'status' => $step->step_order < $currentLevel ? 'Approved' : 'Pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            if (Schema::hasColumn('leaves', 'cancellation_approval_level')) {
                $table->dropColumn('cancellation_approval_level');
            }
        });

        DB::table('leave_approvals')->where('stage', 'cancellation')->delete();

        Schema::table('leave_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('leave_approvals', 'stage')) {
                $table->dropIndex('leave_approvals_stage_step_index');
                $table->dropColumn('stage');
            }
        });
    }
};
