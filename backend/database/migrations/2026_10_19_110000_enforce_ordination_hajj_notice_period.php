<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ลาอุปสมบท / ลาไปประกอบพิธีฮัจญ์ ต้องยื่นล่วงหน้าไม่น้อยกว่า 60 วัน (REDESIGN-2569 เฟส 1.1)
 *
 * ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555 และคู่มือการลาหัวข้อ 6.2:
 * เสนอใบลาก่อนวันอุปสมบทหรือก่อนวันเดินทางไปประกอบพิธีฮัจญ์ไม่น้อยกว่า 60 วัน
 *
 * ค่า 60 วันมีอยู่ที่ `leave_types.notice_period_days` แต่ระบบตรวจจาก `leave_policies`
 * (LeavePolicyService::assertEligible) ซึ่งตั้งไว้ 0 ทุกแถว จึงไม่เคยบังคับจริง
 *
 * แก้แบบมีรุ่น (PROJECT_RULES ข้อ 7 ห้ามเขียนทับประวัติ effective-dated):
 * ปิดรุ่นเดิมเมื่อวานด้วย `effective_to` แล้วเปิดรุ่นใหม่ตั้งแต่วันนี้ — ใบลาที่ยื่นไปแล้วยังผูกรุ่นเดิม
 * แตะเฉพาะรุ่นปัจจุบันที่เปิดไม่มีวันสิ้นสุดและยังเป็นค่า 0 จาก seed — แถวที่ผู้ดูแลตั้งค่าหรือกำหนดวันสิ้นสุดเองไม่ถูกแตะ
 * รุ่นที่ยังไม่เริ่มมีผล (effective_from ตั้งแต่วันนี้) ไม่มีประวัติให้รักษา จึงแก้ค่าในแถวเดิม
 */
return new class extends Migration
{
    private const LEAVE_TYPES = ['ลาอุปสมบท', 'ลาไปประกอบพิธีฮัจญ์'];

    private const NOTICE_DAYS = 60;

    public function up(): void
    {
        $today = today();
        $yesterday = $today->copy()->subDay()->toDateString();

        $policies = DB::table('leave_policies')
            ->whereIn('leave_type_id', $this->leaveTypeIds())
            ->where('is_active', true)
            ->where('notice_period_days', 0)
            ->whereNull('effective_to')
            ->get();

        foreach ($policies as $policy) {
            if ($policy->effective_from >= $today->toDateString()) {
                DB::table('leave_policies')->where('id', $policy->id)
                    ->update(['notice_period_days' => self::NOTICE_DAYS, 'updated_at' => now()]);

                continue;
            }

            $next = (array) $policy;
            unset($next['id']);
            $next['effective_from'] = $today->toDateString();
            $next['notice_period_days'] = self::NOTICE_DAYS;
            $next['created_at'] = now();
            $next['updated_at'] = now();

            DB::table('leave_policies')->where('id', $policy->id)
                ->update(['effective_to' => $yesterday, 'updated_at' => now()]);
            DB::table('leave_policies')->insert($next);
        }
    }

    /**
     * ลบเฉพาะรุ่นใหม่ที่ migration นี้สร้างและยังไม่มีใบลาหรือยอดวันลาอ้างถึง แล้วเปิดรุ่นเดิมกลับ
     * รุ่นที่ถูกใช้งานแล้วคงไว้ เพื่อไม่ให้ใบลาหลุดจากนโยบายที่ใช้ตัดสิน
     */
    public function down(): void
    {
        $created = DB::table('leave_policies')
            ->whereIn('leave_type_id', $this->leaveTypeIds())
            ->where('notice_period_days', self::NOTICE_DAYS)
            ->get();

        foreach ($created as $policy) {
            $previous = DB::table('leave_policies')
                ->where('personnel_type_id', $policy->personnel_type_id)
                ->where('leave_type_id', $policy->leave_type_id)
                ->where('notice_period_days', 0)
                ->whereDate('effective_to', Carbon::parse($policy->effective_from)->subDay())
                ->first();

            if (! $previous) {
                continue;
            }

            $inUse = DB::table('leaves')->where('leave_policy_id', $policy->id)->exists()
                || DB::table('leave_balances')->where('leave_policy_id', $policy->id)->exists();
            if ($inUse) {
                continue;
            }

            DB::table('leave_policies')->where('id', $policy->id)->delete();
            DB::table('leave_policies')->where('id', $previous->id)->update(['effective_to' => null, 'updated_at' => now()]);
        }
    }

    private function leaveTypeIds(): array
    {
        return DB::table('leave_types')->whereIn('name', self::LEAVE_TYPES)->pluck('id')->all();
    }
};
