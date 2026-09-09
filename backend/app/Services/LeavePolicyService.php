<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeavePolicyService
{
    public function resolve(User $user, int $leaveTypeId, Carbon $date): LeavePolicy
    {
        // 1. Resolve personnel_type_id from Personnel Domain assignment
        $personnelTypeId = $user->relationLoaded('currentAssignment')
            ? $user->currentAssignment?->personnel_type_id
            : null;

        if ($personnelTypeId === null) {
            $personnelTypeId = DB::table('personnel_assignments')
                ->where('user_id', $user->id)
                ->whereDate('effective_from', '<=', $date->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->value('personnel_type_id');
        }

        // 2. Fallback to users.personnel_type_id
        $personnelTypeId ??= $user->personnel_type_id;

        if (! $personnelTypeId) {
            $personnelTypeId = PersonnelType::where('code', 'civil_servant')->value('id');
            $user->personnel_type_id = $personnelTypeId;
            $user->save();
        }

        $policy = LeavePolicy::where('personnel_type_id', $personnelTypeId)->where('leave_type_id', $leaveTypeId)->where('is_active', true)->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
        if ($policy) {
            return $policy;
        }
        $type = LeaveType::findOrFail($leaveTypeId);
        $legacyQuota = LeaveBalance::where('user_id', $user->id)->where('leave_type_id', $leaveTypeId)->value('quota');

        return LeavePolicy::create(['personnel_type_id' => $personnelTypeId, 'leave_type_id' => $leaveTypeId, 'effective_from' => $date->copy()->startOfYear(), 'annual_quota' => $legacyQuota ?? $type->max_days, 'notice_period_days' => $type->notice_period_days ?? 0, 'allow_past_dates' => $type->allow_past_dates ?? false, 'exclude_holidays' => $type->exclude_holidays ?? true, 'allow_half_day' => true, 'attachment_required_after_days' => $type->require_attachment_after_days, 'required_evidence_type' => ($type->require_attachment_after_days !== null ? 'supporting_document' : null), 'is_carry_forward' => $type->is_carry_forward ?? false, 'max_carry_forward' => $type->max_carry_forward ?? 0, 'period_basis' => 'fiscal_year', 'is_active' => true]);
    }

    public function fiscalYear(Carbon $date): ?FiscalYear
    {
        return FiscalYear::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
    }

    /**
     * เลขปีงบประมาณที่ใช้เป็นคีย์ของ leave_balances.year — เก็บเป็น "ค.ศ. ของปีที่ปีงบประมาณสิ้นสุด" เสมอ
     * (ปีงบ พ.ศ.2569 = 1 ต.ค. 2025 – 30 ก.ย. 2026 → คีย์คือ 2026)
     * ตาราง fiscal_years เก็บ year เป็น พ.ศ. จึงต้องลบ 543 ก่อนใช้ ห้ามอ่าน ->year ตรง ๆ ที่อื่น
     */
    public function fiscalYearNumber(?FiscalYear $fy, Carbon $date): int
    {
        if ($fy) {
            $y = (int) $fy->year;

            return $y > 2400 ? $y - 543 : $y;
        }

        return $date->month >= 10 ? $date->year + 1 : $date->year;
    }

    public function periodYear(LeavePolicy $policy, Carbon $date): int
    {
        return $policy->period_basis === 'calendar_year' ? $date->year : $this->fiscalYearNumber($this->fiscalYear($date), $date);
    }

    /** ปีของ leave_balances ที่ใบลาใบหนึ่งต้องไปตัด — ใช้ร่วมกันทุกที่ที่ต้องหา balance ของใบลา */
    public function periodYearForLeave(Leave $leave): int
    {
        $start = Carbon::parse($leave->start_date);

        return $leave->policy ? $this->periodYear($leave->policy, $start) : $this->fiscalYearNumber($this->fiscalYear($start), $start);
    }

    public function assertEligible(User $user, LeavePolicy $policy, Carbon $start, Carbon $end, float $days, string $period, bool $hasEvidence): void
    {
        $startDateVal = $user->personnel?->effective_from
            ? $user->personnel->effective_from->toDateString()
            : ($user->profile?->start_date ? Carbon::parse($user->profile->start_date)->toDateString() : null);

        $serviceStart = $startDateVal ? Carbon::parse($startDateVal) : null;
        if ($serviceStart && $serviceStart->diffInDays($start, false) < $policy->minimum_service_days) {
            throw ValidationException::withMessages(['start_date' => 'อายุราชการ/อายุงานยังไม่ครบตามหลักเกณฑ์']);
        }if (! $policy->allow_past_dates && $start->lt(today())) {
            throw ValidationException::withMessages(['start_date' => 'ไม่อนุญาตให้ยื่นลาย้อนหลัง']);
        }if ($policy->notice_period_days > 0 && today()->diffInDays($start, false) < $policy->notice_period_days) {
            throw ValidationException::withMessages(['start_date' => "ต้องยื่นลาล่วงหน้าอย่างน้อย {$policy->notice_period_days} วัน"]);
        }if ($policy->max_days_per_request && $days > $policy->max_days_per_request) {
            throw ValidationException::withMessages(['end_date' => 'จำนวนวันลาเกินเพดานต่อครั้ง']);
        }if ($period !== 'full_day' && ! $policy->allow_half_day) {
            throw ValidationException::withMessages(['leave_period' => 'การลาประเภทนี้ไม่อนุญาตให้ลาครึ่งวัน']);
        }if ($policy->attachment_required_after_days !== null && $days >= $policy->attachment_required_after_days && ! $hasEvidence) {
            throw ValidationException::withMessages(['attachment' => 'ต้องแนบหลักฐานประกอบการลาตามหลักเกณฑ์']);
        }if ($this->periodYear($policy, $start) !== $this->periodYear($policy, $end)) {
            throw ValidationException::withMessages(['end_date' => $policy->period_basis === 'calendar_year'
                ? 'กรุณาแยกใบลาที่ข้ามปีปฏิทิน'
                : 'กรุณาแยกใบลาที่ข้ามปีงบประมาณ']);
        }
    }

    public function balance(User $user, LeavePolicy $policy, Carbon $start): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(['user_id' => $user->id, 'leave_type_id' => $policy->leave_type_id, 'year' => $this->periodYear($policy, $start)], ['quota' => $policy->annual_quota ?? 0, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0, 'leave_policy_id' => $policy->id]);
    }

    /**
     * ปรับ quota ของ leave_balances ที่มีอยู่แล้วให้ตรงกับนโยบายที่เพิ่งบันทึก
     * (แถวเดิมถูกสร้างด้วย firstOrCreate จึงไม่อัปเดตตามนโยบายเอง)
     * แตะเฉพาะ quota/leave_policy_id — ไม่ยุ่งกับ used, reserved, carried_forward
     */
    /**
     * นโยบายนี้เป็นฉบับที่ "ใช้บังคับอยู่จริง ณ วันนี้" หรือไม่
     *
     * LS-02 — เดิม `syncBalances()` เขียนทับยอดปัจจุบันทุกครั้งที่บันทึกนโยบายใด ๆ ก็ตาม
     * นโยบายที่ปิดใช้งาน หมดอายุไปแล้ว หรือยังไม่ถึงวันมีผล จึงลดสิทธิของคนทั้งองค์กรได้ทันที
     * ทั้งที่ยังไม่มีผลบังคับ และการบันทึกนโยบายรุ่นเก่าไว้เป็นประวัติก็ไม่ควรกระทบยอดปัจจุบัน
     *
     * ยึดกติกาเดียวกับ `resolve()` คือ ต้อง active, อยู่ในช่วงมีผล ณ วันนี้ และเป็นฉบับที่
     * `effective_from` ใหม่ที่สุดในบรรดาฉบับที่มีผลอยู่ (ถ้ามีฉบับที่ใหม่กว่า ฉบับนั้นคือผู้ครอง)
     */
    public function isCurrentlyGoverning(LeavePolicy $policy): bool
    {
        if (! $policy->is_active) {
            return false;
        }

        $today = today();

        if (Carbon::parse($policy->effective_from)->gt($today)) {
            return false;
        }

        if ($policy->effective_to !== null && Carbon::parse($policy->effective_to)->lt($today)) {
            return false;
        }

        return ! LeavePolicy::where('personnel_type_id', $policy->personnel_type_id)
            ->where('leave_type_id', $policy->leave_type_id)
            ->where('id', '!=', $policy->id)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->where(function ($query) use ($policy) {
                $query->whereDate('effective_from', '>', $policy->effective_from)
                    ->orWhere(fn ($tie) => $tie->whereDate('effective_from', '=', $policy->effective_from)->where('id', '>', $policy->id));
            })
            ->exists();
    }

    public function syncBalances(LeavePolicy $policy): array
    {
        if (! $this->isCurrentlyGoverning($policy)) {
            // บันทึกนโยบายไว้ได้ แต่ยอดคงเหลือปัจจุบันไม่ถูกแตะจนกว่านโยบายนี้จะมีผลบังคับจริง
            return ['synced' => 0, 'over_quota' => 0, 'year' => $this->periodYear($policy, Carbon::parse($policy->effective_from)), 'applied' => false];
        }

        return DB::transaction(function () use ($policy) {
            $effective = Carbon::parse($policy->effective_from);
            $year = $this->periodYear($policy, $effective->isFuture() ? $effective : today());
            $userIds = User::where('personnel_type_id', $policy->personnel_type_id)->pluck('id');
            if ($userIds->isEmpty()) {
                return ['synced' => 0, 'over_quota' => 0, 'year' => $year, 'applied' => true];
            }
            $scope = fn () => LeaveBalance::whereIn('user_id', $userIds)->where('leave_type_id', $policy->leave_type_id)->where('year', $year);
            $synced = $scope()->update(['quota' => (float) ($policy->annual_quota ?? 0), 'leave_policy_id' => $policy->id, 'updated_at' => now()]);
            $overQuota = $scope()->whereRaw('used + reserved > quota + carried_forward')->count();

            return ['synced' => $synced, 'over_quota' => $overQuota, 'year' => $year, 'applied' => true];
        });
    }
}
