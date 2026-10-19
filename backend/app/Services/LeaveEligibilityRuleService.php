<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Time\TimeService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ตรวจเงื่อนไขเฉพาะของการลาแต่ละประเภทตามระเบียบ จากคอลัมน์ leave_policies.eligibility_rules (JSON)
 *
 * รูปแบบที่รองรับ (ใส่เฉพาะข้อที่ต้องการบังคับ):
 * {
 *   "once_in_career": true,        // ลาได้ครั้งเดียวตลอดอายุราชการ (ลาอุปสมบท / ลาฮัจญ์)
 *   "gender": "female",            // จำกัดเพศตามข้อมูลในทะเบียนประวัติ (ลาคลอดบุตร)
 *   "min_days_between": 365,       // เว้นระยะจากการลาประเภทเดียวกันครั้งก่อนอย่างน้อยกี่วัน
 *   "max_times_per_year": 2,       // จำนวนครั้งสูงสุดต่อรอบปีของนโยบาย
 *   "requires_predecessor_leave_type_id": 3  // ต้องเริ่มต่อจากวันสุดท้ายของการลาประเภทนี้ที่อนุมัติแล้ว
 *                                            // เว้นได้เฉพาะวันหยุดที่คั่น (ลาเลี้ยงดูบุตรต่อจากลาคลอด)
 * }
 */
class LeaveEligibilityRuleService
{
    /** สถานะใบลาที่ถือว่า "ใช้สิทธิ์ไปแล้ว" — ที่ถูกปฏิเสธหรือยกเลิกไม่นับ */
    private const CONSUMING_STATUSES = ['Pending', 'Approved'];

    public function __construct(private LeavePolicyService $policies, private TimeService $time) {}

    public function assert(User $user, LeavePolicy $policy, Carbon $start): void
    {
        $rules = $policy->eligibility_rules;
        if (! is_array($rules) || $rules === []) {
            return;
        }

        $this->assertOnceInCareer($user, $policy, $rules);
        $this->assertGender($user, $rules);
        $this->assertSpacing($user, $policy, $start, $rules);
        $this->assertTimesPerYear($user, $policy, $start, $rules);
        $this->assertPredecessor($user, $start, $rules);
    }

    /**
     * ลาต่อเนื่องจากการลาอีกประเภท — ลากิจส่วนตัวเพื่อเลี้ยงดูบุตรต้องเริ่มหลังวันสุดท้ายของลาคลอด
     * (คู่มือการลาหัวข้อ 4.2 · เจ้าของระบบยืนยัน 18/10/2569) ระหว่างสองใบลาต้องไม่มีวันทำงานคั่น
     */
    private function assertPredecessor(User $user, Carbon $start, array $rules): void
    {
        $typeId = (int) ($rules['requires_predecessor_leave_type_id'] ?? 0);
        if ($typeId <= 0) {
            return;
        }

        $typeName = LeaveType::whereKey($typeId)->value('name') ?? 'การลาที่กำหนด';
        $previous = Leave::where('user_id', $user->id)
            ->where('leave_type_id', $typeId)
            ->where('status', 'Approved')
            ->whereDate('end_date', '<', $start->toDateString())
            ->orderByDesc('end_date')
            ->first();

        if (! $previous) {
            throw ValidationException::withMessages([
                'leave_type_id' => "การลาประเภทนี้ต้องลาต่อเนื่องจาก{$typeName}ที่ได้รับอนุญาตแล้ว",
            ]);
        }

        $end = Carbon::parse($previous->end_date)->startOfDay();
        for ($day = $end->copy()->addDay(); $day->lt($start->copy()->startOfDay()); $day->addDay()) {
            if ($this->time->isWorkingDay($day, $user)) {
                throw ValidationException::withMessages([
                    'start_date' => "ต้องเริ่มลาต่อจากวันสุดท้ายของ{$typeName} (".$end->format('j/n/').($end->year + 543).') โดยไม่มีวันทำงานคั่น',
                ]);
            }
        }
    }

    /** ใบลาประเภทเดียวกันของผู้ใช้คนนี้ที่ยังนับเป็นการใช้สิทธิ์ */
    private function historyQuery(User $user, LeavePolicy $policy)
    {
        return Leave::where('user_id', $user->id)
            ->where('leave_type_id', $policy->leave_type_id)
            ->whereIn('status', self::CONSUMING_STATUSES);
    }

    private function assertOnceInCareer(User $user, LeavePolicy $policy, array $rules): void
    {
        if (empty($rules['once_in_career'])) {
            return;
        }

        if ($this->historyQuery($user, $policy)->exists()) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'การลาประเภทนี้ใช้สิทธิ์ได้เพียงครั้งเดียวตลอดอายุราชการ และพบว่าท่านเคยใช้สิทธิ์แล้ว',
            ]);
        }
    }

    private function assertGender(User $user, array $rules): void
    {
        $required = $rules['gender'] ?? null;
        if (! $required) {
            return;
        }

        $gender = $user->profile?->gender;
        if (! $gender) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'การลาประเภทนี้จำกัดเพศผู้มีสิทธิ์ กรุณาปรับปรุงข้อมูลเพศในทะเบียนประวัติก่อน',
            ]);
        }

        if (! $this->genderMatches($gender, $required)) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'ท่านไม่อยู่ในเงื่อนไขผู้มีสิทธิ์ลาประเภทนี้ตามระเบียบ',
            ]);
        }
    }

    /** ข้อมูลเพศในระบบมีทั้งภาษาไทยและอังกฤษ จึงต้องเทียบทั้งสองแบบ */
    private function genderMatches(string $gender, string $required): bool
    {
        $female = ['female', 'f', 'หญิง', 'เพศหญิง'];
        $male = ['male', 'm', 'ชาย', 'เพศชาย'];
        $normalized = mb_strtolower(trim($gender));

        return match (mb_strtolower($required)) {
            'female' => in_array($normalized, $female, true),
            'male' => in_array($normalized, $male, true),
            default => true,
        };
    }

    private function assertSpacing(User $user, LeavePolicy $policy, Carbon $start, array $rules): void
    {
        $minDays = (int) ($rules['min_days_between'] ?? 0);
        if ($minDays <= 0) {
            return;
        }

        $lastEnd = $this->historyQuery($user, $policy)->max('end_date');
        if (! $lastEnd) {
            return;
        }

        $elapsed = Carbon::parse($lastEnd)->diffInDays($start, false);
        if ($elapsed < $minDays) {
            throw ValidationException::withMessages([
                'start_date' => "การลาประเภทนี้ต้องเว้นระยะจากครั้งก่อนอย่างน้อย {$minDays} วัน (ครั้งก่อนสิ้นสุด ".Carbon::parse($lastEnd)->format('d/m/').(Carbon::parse($lastEnd)->year + 543).')',
            ]);
        }
    }

    private function assertTimesPerYear(User $user, LeavePolicy $policy, Carbon $start, array $rules): void
    {
        $maxTimes = (int) ($rules['max_times_per_year'] ?? 0);
        if ($maxTimes <= 0) {
            return;
        }

        $year = $this->policies->periodYear($policy, $start);
        $periodStart = $policy->period_basis === 'calendar_year'
            ? Carbon::create($year, 1, 1)->startOfDay()
            : Carbon::create($year - 1, 10, 1)->startOfDay();
        $periodEnd = $policy->period_basis === 'calendar_year'
            ? Carbon::create($year, 12, 31)->endOfDay()
            : Carbon::create($year, 9, 30)->endOfDay();

        $used = $this->historyQuery($user, $policy)
            ->whereBetween('start_date', [$periodStart, $periodEnd])
            ->count();

        if ($used >= $maxTimes) {
            throw ValidationException::withMessages([
                'leave_type_id' => "การลาประเภทนี้ใช้สิทธิ์ได้ไม่เกิน {$maxTimes} ครั้งต่อปี และท่านใช้ครบแล้ว",
            ]);
        }
    }
}
