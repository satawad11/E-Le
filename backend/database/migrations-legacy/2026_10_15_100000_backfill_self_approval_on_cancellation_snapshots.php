<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เติม allow_self_approval ให้ snapshot ของสายคำขอยกเลิก (stage = cancellation)
 *
 * ฐานข้อมูลที่รัน 2026_10_14_100000 (อนุมัติของตนเองรายขั้น) ไปก่อนจะได้รับสายคำขอยกเลิก
 * แยก (2026_08_19_140000) ทีหลัง — migration นั้นสร้าง snapshot ของคำขอยกเลิกที่ค้างอยู่
 * จาก approval_steps โดยไม่รู้จัก allow_self_approval จึงได้ค่า false ทุกแถว
 *
 * คัดลอกค่าจากขั้นของสายคำขอยกเลิก ถ้าสายนั้นยังไม่ได้ตั้งค่าใช้สายของการขอลา
 * (เหมือน ApprovalStep::stepsFor) · แตะเฉพาะคำขอยกเลิกที่ยังรอพิจารณา
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_approvals', 'stage') || ! Schema::hasColumn('approval_steps', 'chain')) {
            return;
        }

        $chain = DB::table('approval_steps')->where('chain', 'cancellation')->exists() ? 'cancellation' : 'leave';
        $steps = DB::table('approval_steps')->where('chain', $chain)->get(['step_order', 'allow_self_approval']);

        $pending = DB::table('leaves')->where('status', 'Approved')->where('cancellation_status', 'Pending')->select('id');

        foreach ($steps as $step) {
            DB::table('leave_approvals')
                ->where('stage', 'cancellation')
                ->where('step_order', $step->step_order)
                ->where('status', 'Pending')
                ->whereIn('leave_id', $pending)
                ->update(['allow_self_approval' => (bool) $step->allow_self_approval]);
        }
    }

    public function down(): void
    {
        // ข้อมูลอย่างเดียว ไม่ย้อน
    }
};
