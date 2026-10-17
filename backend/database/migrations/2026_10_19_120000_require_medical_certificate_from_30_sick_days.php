<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ลาป่วยต้องแนบใบรับรองแพทย์เมื่อลาตั้งแต่ 30 วันขึ้นไป (REDESIGN-2569 · D-06 เจ้าของระบบตัดสิน 18/10/2569)
 *
 * ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555 และคู่มือการลาหัวข้อ 1.2:
 * ลาป่วยตั้งแต่ 30 วันขึ้นไปต้องมีใบรับรองแพทย์ ส่วนการลาไม่ถึง 30 วัน ผู้มีอำนาจจะสั่งให้มีใบรับรองแพทย์ก็ได้
 * (การสั่งให้แนบรายใบเป็นงานเฟส 2 — `leave_approvals.evidence_requested`)
 *
 * เดิมนโยบายตั้งไว้ 3 วัน ซึ่งเข้มกว่าระเบียบ ใช้กับข้าราชการและลูกจ้างประจำ (ระเบียบกระทรวงการคลังว่าด้วย
 * การลาของลูกจ้างประจำใช้หลักเดียวกัน) — ประเภทบุคลากรอื่นรอ D-03 จึงไม่แตะ
 *
 * แก้แบบมีรุ่นเหมือน 2026_10_19_110000: ปิดรุ่นเดิมเมื่อวาน เปิดรุ่นใหม่วันนี้ แตะเฉพาะรุ่นปัจจุบันที่ไม่มีวันสิ้นสุด
 * และยังเป็นค่า 3 จาก seed — ค่าที่ผู้ดูแลตั้งเองไม่ถูกแตะ
 */
return new class extends Migration
{
    private const LEAVE_TYPE = 'ลาป่วย';

    private const PERSONNEL_TYPES = ['civil_servant', 'permanent_employee'];

    private const OLD_DAYS = 3;

    private const NEW_DAYS = 30;

    public function up(): void
    {
        $today = today();

        $policies = $this->scope()
            ->where('attachment_required_after_days', self::OLD_DAYS)
            ->where('is_active', true)
            ->whereNull('effective_to')
            ->get();

        foreach ($policies as $policy) {
            if ($policy->effective_from >= $today->toDateString()) {
                DB::table('leave_policies')->where('id', $policy->id)
                    ->update(['attachment_required_after_days' => self::NEW_DAYS, 'updated_at' => now()]);

                continue;
            }

            $next = (array) $policy;
            unset($next['id']);
            $next['effective_from'] = $today->toDateString();
            $next['attachment_required_after_days'] = self::NEW_DAYS;
            $next['created_at'] = now();
            $next['updated_at'] = now();

            DB::table('leave_policies')->where('id', $policy->id)
                ->update(['effective_to' => $today->copy()->subDay()->toDateString(), 'updated_at' => now()]);
            DB::table('leave_policies')->insert($next);
        }
    }

    /** ลบรุ่นใหม่ที่ยังไม่มีใบลาหรือยอดวันลาอ้างถึง แล้วเปิดรุ่นเดิมกลับ */
    public function down(): void
    {
        foreach ($this->scope()->where('attachment_required_after_days', self::NEW_DAYS)->get() as $policy) {
            $previous = $this->scope()
                ->where('personnel_type_id', $policy->personnel_type_id)
                ->where('attachment_required_after_days', self::OLD_DAYS)
                ->whereDate('effective_to', Carbon::parse($policy->effective_from)->subDay())
                ->first();

            if (! $previous
                || DB::table('leaves')->where('leave_policy_id', $policy->id)->exists()
                || DB::table('leave_balances')->where('leave_policy_id', $policy->id)->exists()) {
                continue;
            }

            DB::table('leave_policies')->where('id', $policy->id)->delete();
            DB::table('leave_policies')->where('id', $previous->id)->update(['effective_to' => null, 'updated_at' => now()]);
        }
    }

    private function scope()
    {
        return DB::table('leave_policies')
            ->whereIn('leave_type_id', DB::table('leave_types')->where('name', self::LEAVE_TYPE)->pluck('id'))
            ->whereIn('personnel_type_id', DB::table('personnel_types')->whereIn('code', self::PERSONNEL_TYPES)->pluck('id'));
    }
};
