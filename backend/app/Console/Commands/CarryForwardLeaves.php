<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\LeavePolicyService;
use Carbon\Carbon;
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
        $closingDate = Carbon::create($fromYear, 9, 30);

        /*
         * ไล่ตามผู้ใช้แล้วหานโยบายของแต่ละคน (ไม่ใช่ไล่ตาม users.personnel_type_id)
         * เดิมกรอง User::where('personnel_type_id', ...) ผู้ที่ยังไม่ได้กำหนดประเภทบุคลากร
         * (ค่าว่าง) จึงไม่เคยได้ทบวันลาเลย ทั้งที่ระบบถือว่าเป็นข้าราชการตอนยื่นลา
         */
        $leaveTypeIds = $carryPolicies->pluck('leave_type_id')->unique();
        $users = User::with(['personnel', 'profile'])->where('is_active', true)->get();

        foreach ($users as $user) {
            foreach ($leaveTypeIds as $leaveTypeId) {
                $policy = $policies->resolve($user, (int) $leaveTypeId, $closingDate->copy());
                if (! $policy->is_active || ! $policy->is_carry_forward) {
                    continue;
                }
                $cap = $this->capFor($policy, $user, $closingDate);
                $userId = $user->id;

                $previous = LeaveBalance::where('user_id', $userId)
                    ->where('leave_type_id', $policy->leave_type_id)
                    ->where('year', $fromYear)
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

                DB::transaction(function () use ($userId, $policy, $toYear, $carry) {
                    $next = LeaveBalance::firstOrNew([
                        'user_id' => $userId,
                        'leave_type_id' => $policy->leave_type_id,
                        'year' => $toYear,
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

    /**
     * เพดานวันที่ทบได้ = น้อยที่สุดระหว่างเพดานต่อปี กับ (เพดานสะสมรวม - สิทธิ์ประจำปีของปีใหม่)
     *
     * ระเบียบฯ ข้อ 28: ข้าราชการที่รับราชการติดต่อกันมาแล้วไม่น้อยกว่า 10 ปี
     * สะสมรวมกับปีปัจจุบันได้ไม่เกิน 30 วันทำการ (ต่ำกว่า 10 ปี ไม่เกิน 20 วันทำการ)
     * ตั้งได้ที่ eligibility_rules ของนโยบาย:
     *   {"long_service_years": 10, "max_accumulated_long_service": 30}
     */
    private function capFor(LeavePolicy $policy, User $user, Carbon $on): float
    {
        $maxAccumulated = $policy->max_accumulated;
        $rules = is_array($policy->eligibility_rules) ? $policy->eligibility_rules : [];
        $longYears = (int) ($rules['long_service_years'] ?? 0);
        if ($longYears > 0 && isset($rules['max_accumulated_long_service'])) {
            $serviceStart = $user->personnel?->effective_from ?? ($user->profile?->start_date ? Carbon::parse($user->profile->start_date) : null);
            if ($serviceStart && Carbon::parse($serviceStart)->diffInYears($on) >= $longYears) {
                $maxAccumulated = (float) $rules['max_accumulated_long_service'];
            }
        }

        $cap = $policy->max_carry_forward !== null ? (float) $policy->max_carry_forward : INF;
        if ($maxAccumulated !== null) {
            $cap = min($cap, max(0, (float) $maxAccumulated - (float) ($policy->annual_quota ?? 0)));
        }

        return $cap;
    }
}
