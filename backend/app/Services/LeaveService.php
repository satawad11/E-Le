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
use App\Services\Workflow\LeaveWorkflowShadow;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // LEGACY-07 — เปิด workflow instance ให้ใบลาใหม่ (ยังเป็น shadow)
        private LeaveWorkflowShadow $workflow,
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
    public function calculateTotalDays($startDate, $endDate, bool $excludeHolidays = true, ?int $departmentId = null, ?User $user = null): float
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        if (! $excludeHolidays) {
            return $this->time->calendarDaysBetween($start, $end);
        }

        /*
         * LEGACY-07 — ส่ง `$user` เข้าไปด้วย เพื่อให้ตารางเวลาเฉพาะบุคคล/หน่วยงาน
         * มีผลกับการนับวันลา ผู้ที่ทำงานเสาร์ตามตารางของตนต้องถูกนับวันลาวันเสาร์ด้วย
         *
         * เรียกโดยไม่ส่ง `$user` ได้เหมือนเดิม (ใช้ตารางระดับองค์กร) เพื่อไม่ให้
         * ผู้เรียกเดิมพัง
         */
        return $this->time->workingDaysBetween($start, $end, $user, $departmentId);
    }

    /**
     * @param  array{skip_timing_rules?: bool}  $options  skip_timing_rules — ฝ่ายบุคคลบันทึกวันลาแทน
     *                                                    (ส่วนใหญ่เป็นการลาที่ผ่านไปแล้ว) จึงข้ามกฎ "ห้ามลาย้อนหลัง"
     *                                                    และ "ต้องยื่นล่วงหน้า" แต่กฎอื่นยังบังคับครบ
     */
    public function createLeaveRequest(array $data, int $userId, ?UploadedFile $attachment, array $options = []): Leave
    {
        $leave = DB::transaction(function () use ($data, $userId, $attachment, $options) {
            $user = User::with(['personnel', 'currentAssignment', 'personnelType', 'profile'])->lockForUpdate()->findOrFail($userId);
            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);
            $policy = $this->policies->resolve($user, (int) $data['leave_type_id'], $start);
            $period = $data['leave_period'] ?? 'full_day';
            if ($period !== 'full_day' && ! $start->isSameDay($end)) {
                throw ValidationException::withMessages(['leave_period' => 'ลาครึ่งวันต้องเป็นวันเดียวกัน']);
            }
            $workingDays = $this->calculateTotalDays($start, $end, $policy->exclude_holidays, $user->department_id, $user);
            // ลาครึ่งวันต้องตรงกับวันทำการด้วย — เดิมคืน 0.5 เสมอ ลาครึ่งวันในวันเสาร์/วันหยุดจึงถูกหักวันลา
            $days = $period === 'full_day' ? $workingDays : ($workingDays > 0 ? 0.5 : 0.0);
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'ช่วงวันที่เลือกไม่มีวันทำการ']);
            }
            if ($policy->requires_substitute && ! ($data['substitute_id'] ?? null)) {
                throw ValidationException::withMessages(['substitute_id' => 'ต้องระบุผู้ปฏิบัติงานแทน']);
            }
            $this->policies->assertEligible($user, $policy, $start, $end, $days, $period, (bool) $attachment, (bool) ($options['skip_timing_rules'] ?? false));
            // เงื่อนไขเฉพาะประเภทลาตามระเบียบ เช่น ลาอุปสมบทได้ครั้งเดียวตลอดอายุราชการ
            $this->eligibilityRules->assert($user, $policy, $start);

            if ($this->hasOverlap($userId, $start, $end, $period)) {
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
                'meta_data' => $this->metaData($data),
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
            /*
             * snapshot สายอนุมัติ ณ วันยื่น — ต้องคัดลอก "วิธีหาตัวผู้อนุมัติ" ให้ครบ
             * ไม่ใช่แค่ role เพราะขั้นตอนแบบหัวหน้าแผนกต้นสังกัด/ผอ./ผู้ใช้เจาะจง
             * ไม่ได้ผูกกับ role ใด ๆ ถ้าตกหล่นจะกลายเป็นขั้นตอนแบบ ROLE ทั้งหมด
             * แล้วผู้อนุมัติตัวจริงจะถูกปฏิเสธสิทธิ์ในขั้นของตัวเอง
             */
            foreach (ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->load('role') as $step) {
                LeaveApproval::firstOrCreate(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => $step->step_order], [
                    'step_name' => $step->name, 'required_role_id' => $step->role_id, 'status' => 'Pending',
                    'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                    'specific_user_id' => $step->specific_user_id, 'permission_name' => $step->permission_name,
                    'allow_self_approval' => (bool) $step->allow_self_approval,
                ]);
            }
            $this->audit->log($leave, 'created', null, $leave->toArray());

            return $leave;
        }, 5);

        /*
         * LEGACY-07 — เปิด workflow instance ให้ใบลาใบใหม่ (shadow mode)
         *
         * ทำนอก transaction และห่อ try/catch เพราะ workflow engine ยังเป็น
         * shadow อยู่ (LEGACY-05 · ADR-009) ตรรกะเดิมใน `leave_approvals`
         * ยังเป็น source of truth การเปิด instance ไม่สำเร็จจึงต้องไม่ทำให้
         * การยื่นใบลาล้ม
         */
        if (config('workflow.shadow_mode', true)) {
            try {
                $this->workflow->prepare($leave);
            } catch (\Throwable $exception) {
                Log::warning('เปิด workflow instance ให้ใบลาไม่สำเร็จ', [
                    'leave_id' => $leave->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $leave;
    }

    /**
     * มีใบลาที่ยังมีผลซ้อนช่วงวันนี้อยู่หรือไม่
     *
     * ลาครึ่งเช้ากับครึ่งบ่ายของวันเดียวกันไม่ถือว่าซ้อนกัน — เดิมบล็อกทั้งวัน
     * ทำให้ลาป่วยครึ่งเช้าแล้วลากิจครึ่งบ่ายในวันเดียวกันไม่ได้
     */
    public function hasOverlap(int $userId, Carbon $start, Carbon $end, string $period = 'full_day', ?int $ignoreLeaveId = null): bool
    {
        return Leave::where('user_id', $userId)
            ->whereNotIn('status', ['Cancelled', 'Rejected', 'Voided'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->when($ignoreLeaveId, fn ($query) => $query->whereKeyNot($ignoreLeaveId))
            ->lockForUpdate()
            ->get(['id', 'start_date', 'end_date', 'leave_period'])
            ->contains(function (Leave $other) use ($start, $end, $period) {
                $otherPeriod = $other->leave_period ?: 'full_day';
                $bothHalfSameDay = $period !== 'full_day' && $otherPeriod !== 'full_day'
                    && $start->isSameDay($end) && $other->start_date->isSameDay($start)
                    && $other->end_date->isSameDay($start);

                return ! ($bothHalfSameDay && $otherPeriod !== $period);
            });
    }

    /**
     * ปิดใบลาที่ฝ่ายบุคคลบันทึกแทนให้เป็น "อนุมัติแล้ว" อย่างถูกต้อง
     *
     * เดิมแค่เปลี่ยน status เป็น Approved — วันลาจึงค้างอยู่ใน reserved ตลอดไป
     * (ไม่ถูกนับเป็น used) และขั้นตอนอนุมัติทุกขั้นยังเป็น Pending
     */
    public function finalizeRecordedLeave(Leave $leave, User $recorder, string $note): Leave
    {
        return DB::transaction(function () use ($leave, $recorder, $note) {
            $leave = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $leave->approvals()->where('status', 'Pending')->update([
                'status' => 'Approved',
                'approver_id' => $recorder->id,
                'approver_name' => $recorder->full_name_with_title,
                'approver_position' => $recorder->profile?->position,
                'comment' => $note,
                'approved_at' => now(),
                'acted_at' => now(),
            ]);
            if (! $this->deductBalance($leave)) {
                throw ValidationException::withMessages(['leave_type_id' => 'วันลาคงเหลือไม่เพียงพอ']);
            }
            $last = $leave->approvals()->max('step_order');
            $leave->forceFill([
                'status' => 'Approved',
                'approval_level' => $last ?: $leave->approval_level,
                'hr_verified_by' => $recorder->id,
                'hr_verified_at' => now(),
            ])->save();
            $this->audit->log($leave, 'recorded_by_hr', null, ['recorded_by' => $recorder->id, 'note' => $note]);

            return $leave;
        }, 5);
    }

    /**
     * แก้วันที่/ประเภทของใบลาที่ยังรออนุมัติ — คำนวณจำนวนวันและยอดจองใหม่ทั้งหมด
     *
     * เดิม `update()` เขียนทับวันที่ตรง ๆ โดยไม่คำนวณ total_days และ reserved ใหม่
     * ยอดวันลาคงเหลือจึงเพี้ยนทันทีที่ผู้ดูแลแก้ใบลา
     */
    public function reschedule(Leave $leave, array $data): Leave
    {
        return DB::transaction(function () use ($leave, $data) {
            $leave = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $user = User::with(['personnel', 'currentAssignment', 'personnelType', 'profile'])->findOrFail($leave->user_id);
            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);
            $period = $data['leave_period'] ?? ($leave->leave_period ?: 'full_day');
            if ($period !== 'full_day' && ! $start->isSameDay($end)) {
                throw ValidationException::withMessages(['leave_period' => 'ลาครึ่งวันต้องเป็นวันเดียวกัน']);
            }
            $policy = $this->policies->resolve($user, (int) $data['leave_type_id'], $start);
            $workingDays = $this->calculateTotalDays($start, $end, $policy->exclude_holidays, $user->department_id, $user);
            $days = $period === 'full_day' ? $workingDays : ($workingDays > 0 ? 0.5 : 0.0);
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'ช่วงวันที่เลือกไม่มีวันทำการ']);
            }
            if ($this->hasOverlap($leave->user_id, $start, $end, $period, $leave->id)) {
                throw ValidationException::withMessages(['start_date' => 'มีใบลาในช่วงเวลานี้อยู่แล้ว']);
            }

            $before = $leave->toArray();
            $this->restoreBalance($leave);

            $balance = $this->policies->balance($user, $policy, $start);
            $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
            $available = (float) $balance->quota + (float) $balance->carried_forward - (float) $balance->used - (float) $balance->reserved;
            if ($policy->annual_quota !== null && $available < $days) {
                throw ValidationException::withMessages(['leave_type_id' => 'วันลาคงเหลือไม่เพียงพอ (รวมรายการรออนุมัติแล้ว)']);
            }
            [$paidDays, $unpaidDays] = $this->splitPaidDays($policy, $balance, $days);
            $reserved = $policy->annual_quota !== null ? $days : 0;

            $leave->fill([
                'leave_type_id' => $policy->leave_type_id, 'leave_policy_id' => $policy->id,
                'fiscal_year_id' => $this->policies->fiscalYear($start)?->id,
                'start_date' => $start, 'end_date' => $end, 'leave_period' => $period,
                'total_days' => $days, 'paid_days' => $paidDays, 'unpaid_days' => $unpaidDays,
                'reserved_days' => $reserved, 'reason' => $data['reason'] ?? $leave->reason,
            ])->save();
            if ($reserved > 0) {
                $balance->reserved += $reserved;
                $balance->leave_policy_id = $policy->id;
                $balance->save();
            }
            $this->audit->log($leave, 'rescheduled', $before, $leave->toArray());

            return $leave;
        }, 5);
    }

    /**
     * รวม meta_data ที่ส่งมากับ "ช่องกรอกเฉพาะแบบฟอร์ม" ของประเภทการลา
     *
     * ค่าที่กรอกถูกเก็บใต้คีย์ `extra_fields` เพื่อให้แบบฟอร์มตอนพิมพ์อ่านผ่าน
     * `LeaveDocumentFields` ได้ โดยไม่ต้องเพิ่มคอลัมน์ใหม่ทุกครั้งที่มีแบบฟอร์มใหม่
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function metaData(array $data): ?array
    {
        $meta = $data['meta_data'] ?? null;
        $meta = is_array($meta) ? $meta : (is_string($meta) ? json_decode($meta, true) : null);
        $meta = is_array($meta) ? $meta : [];

        $extra = array_filter(
            (array) ($data['extra_fields'] ?? []),
            fn ($value) => $value !== null && $value !== ''
        );

        if ($extra !== []) {
            $meta['extra_fields'] = $extra;
        }

        return $meta === [] ? null : $meta;
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

    /**
     * คืนวันลาของใบลาที่ถูกยกเลิก/ปฏิเสธ
     *
     * ใบที่ยังรออนุมัติ → คืนยอดที่จองไว้ (reserved)
     * ใบที่อนุมัติแล้ว → คืนวันที่ใช้ไป (used) — ต้องเรียก **ก่อน** เปลี่ยน status
     * หรือส่ง $wasApproved มาเอง มิฉะนั้นวันลาจะไม่ถูกคืน
     */
    public function restoreBalance(Leave $leave, ?bool $wasApproved = null): void
    {
        $wasApproved ??= $leave->status === 'Approved';
        DB::transaction(function () use ($leave, $wasApproved) {
            $balance = $this->balanceFor($leave, true);
            if (! $balance) {
                return;
            }
            if ($leave->reserved_days > 0) {
                $balance->reserved = max(0, (float) $balance->reserved - (float) $leave->reserved_days);
            } elseif ($wasApproved) {
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
