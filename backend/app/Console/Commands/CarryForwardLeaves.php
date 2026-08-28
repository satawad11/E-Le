<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\LeavePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ทบวันลาที่เหลือของปีงบประมาณที่ปิดไปแล้ว ไปเป็นวันสะสมของปีถัดไป
 *
 * อิง leave_policies ตามประเภทบุคลากร (ไม่ใช่ leave_types) และเคารพเพดาน 2 ชั้นตามระเบียบ:
 *  - max_carry_forward  = ทบได้สูงสุดกี่วันต่อปี
 *  - max_accumulated    = วันสะสมรวมกับสิทธิ์ประจำปีต้องไม่เกินเท่าไร
 *    (ข้าราชการ 30 วัน = สะสม 20 + สิทธิ์ประจำปี 10, พนักงานราชการ 15 วัน = สะสม 5 + 10)
 *
 * รันซ้ำได้ (idempotent) เพราะเป็นการ "กำหนดค่า" carried_forward ไม่ใช่การบวกเพิ่ม
 */
class CarryForwardLeaves extends Command
{
    protected $signature = 'app:carry-forward-leaves
        {--year= : ปีงบประมาณ (ค.ศ.) ที่ปิดรอบ เช่น 2026 หมายถึงทบจากปี 2026 ไป 2027}
        {--dry-run : แสดงผลที่จะเกิดขึ้นโดยไม่บันทึก}';

    protected $description = 'ทบวันลาคงเหลือของปีงบประมาณที่ปิดรอบ ไปเป็นวันสะสมของปีถัดไปตามนโยบายสิทธิการลา';

    public function handle(LeavePolicyService $policies): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ค่าเริ่มต้น: คำสั่งนี้ถูกตั้งเวลาให้รันเช้าวันที่ 1 ต.ค. ซึ่งนับเป็นปีงบประมาณใหม่แล้ว
        // จึงทบ "จากปีที่เพิ่งปิด" (ปีปัจจุบัน - 1) ไปยังปีที่เพิ่งเริ่ม
        $toYear = $this->option('year')
            ? (int) $this->option('year') + 1
            : $policies->fiscalYearNumber($policies->fiscalYear(today()), today());
        $fromYear = $toYear - 1;

        $this->info('ทบวันลาจากปีงบประมาณ '.($fromYear + 543).' ไปยังปี '.($toYear + 543));

        $carryPolicies = LeavePolicy::with(['leaveType'])
            ->where('is_active', true)
            ->where('is_carry_forward', true)
            ->get();

        if ($carryPolicies->isEmpty()) {
            $this->warn('ไม่มีนโยบายที่เปิดให้ทบวันลาข้ามปี');

            return self::SUCCESS;
        }

        $rows = [];
        $count = 0;

        foreach ($carryPolicies as $policy) {
            /*
             * เลขปีของแถวโควตาต้องมาจากเกณฑ์นับรอบของนโยบายเอง
             *
             * เดิมคิดเป็นปีงบประมาณเสมอ นโยบายที่ตั้งให้นับตามปีปฏิทินจึงถูกอ่านผิดแถว
             * ทั้งฝั่งที่ทบมาและฝั่งที่ทบไป — วันลาสะสมหายหรือไปโผล่ผิดปี
             */
            $policyToYear = $policy->period_basis === 'calendar_year' ? today()->year : $toYear;
            $policyFromYear = $policyToYear - 1;

            // เพดานที่ทบได้จริง = น้อยที่สุดระหว่างเพดานต่อปี กับ (เพดานสะสมรวม - สิทธิ์ประจำปีของปีใหม่)
            $cap = $policy->max_carry_forward ?? INF;
            if ($policy->max_accumulated !== null) {
                $cap = min($cap, max(0, (float) $policy->max_accumulated - (float) ($policy->annual_quota ?? 0)));
            }

            $userIds = User::where('personnel_type_id', $policy->personnel_type_id)
                ->where('is_active', true)
                ->pluck('id');

            foreach ($userIds as $userId) {
                $previous = LeaveBalance::where('user_id', $userId)
                    ->where('leave_type_id', $policy->leave_type_id)
                    ->where('year', $policyFromYear)
                    ->first();
                if (! $previous) {
                    continue;
                }

                // ใบลาที่ยังค้างอนุมัติข้ามปีต้องไม่ถูกนับเป็นวันว่าง
                $remaining = (float) $previous->quota + (float) $previous->carried_forward
                    - (float) $previous->used - (float) $previous->reserved;
                if ($remaining <= 0) {
                    continue;
                }

                $carry = min($remaining, $cap);
                if ($carry <= 0) {
                    continue;
                }

                $rows[] = [$userId, $policy->leaveType?->name ?? $policy->leave_type_id, $remaining, $carry];
                $count++;

                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($userId, $policy, $policyToYear, $carry) {
                    $next = LeaveBalance::firstOrNew([
                        'user_id' => $userId,
                        'leave_type_id' => $policy->leave_type_id,
                        'year' => $policyToYear,
                    ]);
                    if (! $next->exists) {
                        $next->quota = $policy->annual_quota ?? 0;
                        $next->used = 0;
                        $next->reserved = 0;
                    }
                    $next->carried_forward = $carry; // กำหนดค่า ไม่ใช่บวกเพิ่ม จึงรันซ้ำได้
                    $next->leave_policy_id = $policy->id;
                    $next->save();
                });
            }
        }

        if ($rows) {
            $this->table(['user_id', 'ประเภทลา', 'คงเหลือปีเก่า', 'ทบให้'], $rows);
        }

        $this->info($dryRun
            ? "จะทบวันลา {$count} รายการ (โหมด dry-run ยังไม่บันทึก)"
            : "ทบวันลาเรียบร้อย {$count} รายการ");

        return self::SUCCESS;
    }
}
