<?php

namespace App\Services;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveEvidence;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Time\TimeService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(
        private LeavePolicyService $policies,
        private LeaveDocumentNumberService $numbers,
        private LeaveAuditService $audit,
        private LeaveEligibilityRuleService $eligibilityRules,
        private LeaveVerificationService $verification,
        // LEGACY-06 — บริการเวลากลาง โมดูลนี้ไม่คำนวณวันทำงานเองอีกต่อไป
        private TimeService $time,
    ) {}

    /**
     * LEGACY-06 — ส่งต่อให้ `TimeService` ซึ่งเป็นบริการเวลากลางของระบบ
     *
     * เดิมตรรกะวันทำงาน (เสาร์อาทิตย์ + วันหยุดราชการ + exception รายหน่วยงาน)
     * อยู่ในเมธอดนี้ที่เดียว ส่วนโมดูล attendance มีตรรกะเวลาของตัวเองแยกต่างหาก
     * ที่ไม่รู้จักวันหยุดเลย ทั้งระบบจึงตอบคำถามว่า "วันนี้เป็นวันทำงานไหม"
     * ได้ไม่เหมือนกันแล้วแต่ว่าใครถาม
     *
     * ผลลัพธ์เท่าเดิมทุกกรณี — มีเทสต์เทียบไว้ใน `TimeServiceTest`
     */
    public function calculateTotalDays($startDate, $endDate, bool $excludeHolidays = true, ?int $departmentId = null): float
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        if (! $excludeHolidays) {
            return $this->time->calendarDaysBetween($start, $end);
        }

        return $this->time->workingDaysBetween($start, $end, null, $departmentId);
    }

    public function createLeaveRequest(array $data, int $userId, ?UploadedFile $attachment): Leave
    {
        return DB::transaction(function () use ($data, $userId, $attachment) {
            $user = User::with(['personnelType', 'profile'])->lockForUpdate()->findOrFail($userId);
            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);
            $policy = $this->policies->resolve($user, (int) $data['leave_type_id'], $start);
            $period = $data['leave_period'] ?? 'full_day';
            $days = $period === 'full_day'
                ? $this->calculateTotalDays($start, $end, $policy->exclude_holidays, $user->department_id)
                : 0.5;
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'ช่วงวันที่เลือกไม่มีวันทำการ']);
            }
            if ($period !== 'full_day' && ! $start->isSameDay($end)) {
                throw ValidationException::withMessages(['leave_period' => 'ลาครึ่งวันต้องเป็นวันเดียวกัน']);
            }
            if ($policy->requires_substitute && ! ($data['substitute_id'] ?? null)) {
                throw ValidationException::withMessages(['substitute_id' => 'ต้องระบุผู้ปฏิบัติงานแทน']);
            }
            $this->policies->assertEligible($user, $policy, $start, $end, $days, $period, (bool) $attachment);
            // เงื่อนไขเฉพาะประเภทลาตามระเบียบ เช่น ลาอุปสมบทได้ครั้งเดียวตลอดอายุราชการ
            $this->eligibilityRules->assert($user, $policy, $start);

            $overlap = Leave::where('user_id', $userId)->whereNotIn('status', ['Cancelled', 'Rejected', 'Voided'])
                ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'มีใบลาในช่วงเวลานี้อยู่แล้ว']);
            }

            $balance = $this->policies->balance($user, $policy, $start);
            $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
            $available = (float) $balance->quota + (float) $balance->carried_forward - (float) $balance->used - (float) $balance->reserved;
            if ($policy->annual_quota !== null && $available < $days) {
                throw ValidationException::withMessages(['leave_type_id' => 'วันลาคงเหลือไม่เพียงพอ (รวมรายการรออนุมัติแล้ว)']);
            }

            // แยกวันที่ได้รับเงินเดือน/ไม่ได้รับเงินเดือน ตามเพดาน max_days_with_pay ของนโยบาย
            [$paidDays, $unpaidDays] = $this->splitPaidDays($policy, $balance, $days);

            $type = LeaveType::findOrFail($policy->leave_type_id);
            $fiscalYear = $this->policies->fiscalYear($start);
            $number = $this->numbers->next($type->code_prefix ?: 'L', $this->policies->periodYear($policy, $start) + 543);
            $path = $attachment ? Storage::disk('local')->putFile('private/leave-evidences', $attachment) : null;
            $leave = Leave::create([
                'user_id' => $userId, 'leave_type_id' => $policy->leave_type_id, 'leave_policy_id' => $policy->id,
                'fiscal_year_id' => $fiscalYear?->id, 'document_number' => $number, 'start_date' => $start,
                'end_date' => $end, 'leave_period' => $period, 'total_days' => $days,
                'paid_days' => $paidDays, 'unpaid_days' => $unpaidDays,
                'reserved_days' => $policy->annual_quota !== null ? $days : 0, 'reason' => $data['reason'],
                'contact_address' => $data['contact_address'] ?? null, 'status' => 'Pending', 'approval_level' => 1,
                'substitute_id' => $data['substitute_id'] ?? null, 'substitute_status' => isset($data['substitute_id']) ? 'Pending' : null,
                'substitute_reason' => $data['substitute_reason'] ?? null,
                'meta_data' => isset($data['meta_data']) ? (is_array($data['meta_data']) ? $data['meta_data'] : json_decode($data['meta_data'], true)) : null,
                'signature_path' => $data['signature_path'] ?? null, 'notified_supervisor_at' => $data['notified_supervisor_at'] ?? null,
                'verification_hash' => Str::random(40),
                'submitted_at' => now(),
            ]);
            if ($leave->reserved_days > 0) {
                $balance->reserved += (float) $leave->reserved_days;
                $balance->leave_policy_id = $policy->id;
                $balance->save();
            }
            if ($attachment) {
                LeaveEvidence::create([
                    'leave_id' => $leave->id, 'uploaded_by' => $userId,
                    'evidence_type' => $policy->required_evidence_type ?: 'supporting_document',
                    'original_name' => $attachment->getClientOriginalName(), 'storage_path' => $path,
                    'mime_type' => $attachment->getMimeType(), 'file_size' => $attachment->getSize(),
                    'sha256' => hash_file('sha256', $attachment->getRealPath()),
                ]);
            }
            foreach (ApprovalStep::with('role')->orderBy('step_order')->get() as $step) {
                LeaveApproval::firstOrCreate(['leave_id' => $leave->id, 'step_order' => $step->step_order], [
                    'step_name' => $step->name, 'required_role_id' => $step->role_id, 'status' => 'Pending',
                ]);
            }
            $this->audit->log($leave, 'created', null, $leave->toArray());

            return $leave;
        }, 5);
    }

    /**
     * แบ่งจำนวนวันของใบลาออกเป็น "วันที่ได้รับเงินเดือน" กับ "วันที่ไม่ได้รับเงินเดือน"
     * นับจากวันที่ใช้ไปแล้วในรอบปีนี้ (รวมใบที่ยังรออนุมัติ) เทียบกับเพดาน max_days_with_pay
     * นโยบายที่ไม่กำหนดเพดาน = ได้รับเงินเดือนทั้งหมด
     *
     * @return array{0: float, 1: float} [paid_days, unpaid_days]
     */
    public function splitPaidDays(LeavePolicy $policy, ?LeaveBalance $balance, float $days): array
    {
        if ($policy->max_days_with_pay === null) {
            return [$days, 0.0];
        }

        $alreadyTaken = $balance ? (float) $balance->used + (float) $balance->reserved : 0.0;
        $paidRemaining = max(0.0, (float) $policy->max_days_with_pay - $alreadyTaken);
        $paid = min($days, $paidRemaining);

        return [$paid, $days - $paid];
    }

    public function deductBalance(Leave $leave): bool
    {
        return DB::transaction(function () use ($leave) {
            $balance = $this->balanceFor($leave, true);
            if (! $balance) {
                return false;
            }
            $reserved = min((float) $balance->reserved, (float) $leave->reserved_days);
            $available = (float) $balance->quota + (float) $balance->carried_forward - (float) $balance->used - ((float) $balance->reserved - $reserved);
            if ($available < $leave->total_days) {
                return false;
            }
            $balance->reserved -= $reserved;
            $balance->used += (float) $leave->total_days;
            $balance->save();
            $leave->reserved_days = 0;
            $leave->save();
            // ผนึกลายนิ้วมือเนื้อหาไว้ตอนอนุมัติ เพื่อให้ QR ตรวจการแก้ไขย้อนหลังได้
            $this->verification->seal($leave);

            return true;
        }, 5);
    }

    public function restoreBalance(Leave $leave): void
    {
        DB::transaction(function () use ($leave) {
            $balance = $this->balanceFor($leave, true);
            if (! $balance) {
                return;
            }
            if ($leave->reserved_days > 0) {
                $balance->reserved = max(0, (float) $balance->reserved - (float) $leave->reserved_days);
            } elseif ($leave->status === 'Approved') {
                $balance->used = max(0, (float) $balance->used - (float) $leave->total_days);
            }
            $balance->save();
            $leave->reserved_days = 0;
            $leave->save();
        });
    }

    public function hasEnoughBalance(Leave $leave): bool
    {
        $balance = $this->balanceFor($leave, false);

        return $balance && ((float) $balance->quota + (float) $balance->carried_forward - (float) $balance->used - (float) $balance->reserved + (float) $leave->reserved_days) >= $leave->total_days;
    }

    private function balanceFor(Leave $leave, bool $lock): ?LeaveBalance
    {
        $year = $this->policies->periodYearForLeave($leave);
        $query = LeaveBalance::where('user_id', $leave->user_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $year);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }
}
