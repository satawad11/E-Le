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
use App\Services\Attendance\LeaveAttendanceSync;
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
     * สร้างใบลา — เป็น "ร่าง" หรือ "ยื่นเข้าสายอนุมัติ" ก็ได้
     *
     * ร่างคือใบลาที่ยังไม่เข้าสู่กระบวนการ จึงต้องไม่แตะสิ่งที่เป็นของทางราชการเลย:
     *
     *   - **ไม่ออกเลขที่หนังสือ** เลขเป็นลำดับที่เดินหน้าอย่างเดียว ถ้าออกให้ร่างแล้วร่าง
     *     ถูกลบ ทะเบียนหนังสือจะมีเลขขาดเป็นช่วง ๆ ซึ่งอธิบายกับผู้ตรวจสอบไม่ได้
     *   - **ไม่กันโควตาวันลา** ร่างยังไม่ใช่คำขอ จึงไม่ควรไปลดยอดที่คนอื่นเห็น
     *   - **ไม่ผนึก snapshot สายอนุมัติ** ต้องผนึก ณ วันที่ยื่นจริงเท่านั้น
     *   - **ไม่แจ้งเตือนผู้อนุมัติ** และไม่เปิด workflow instance
     *
     * ทั้งสี่อย่างเกิดพร้อมกันตอนกด "ส่งอนุมัติ" ใน `submitDraft()`
     */
    public function createLeaveRequest(array $data, int $userId, ?UploadedFile $attachment, bool $asDraft = false, bool $asApproved = false): Leave
    {
        if ($asDraft && $asApproved) {
            throw new \InvalidArgumentException('ร่างไม่สามารถเป็นรายการอนุมัติแล้วได้');
        }
        $leave = DB::transaction(function () use ($data, $userId, $attachment, $asDraft, $asApproved) {
            $user = User::with(['personnel', 'currentAssignment', 'personnelType', 'profile'])->lockForUpdate()->findOrFail($userId);

            [$policy, $start, $end, $period, $days] = $this->resolveRequest($user, $data, (bool) $attachment);

            // ร่างยังไม่ใช่คำขอ จึงไม่นับเป็นใบลาที่จองช่วงเวลาไว้
            $overlap = Leave::where('user_id', $userId)->whereNotIn('status', ['Draft', 'Cancelled', 'Rejected', 'Voided'])
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

            $fiscalYear = $this->policies->fiscalYear($start);
            $path = $attachment ? Storage::disk('local')->putFile('private/leave-evidences', $attachment) : null;
            $leave = Leave::create([
                'user_id' => $userId, 'leave_type_id' => $policy->leave_type_id, 'leave_policy_id' => $policy->id,
                'fiscal_year_id' => $fiscalYear?->id,
                // ร่างยังไม่ออกเลขที่หนังสือ — ออกตอนกดส่งอนุมัติเท่านั้น
                'document_number' => $asDraft ? null : $this->nextDocumentNumber($policy, $start),
                'start_date' => $start,
                'end_date' => $end, 'leave_period' => $period, 'total_days' => $days,
                'paid_days' => $paidDays, 'unpaid_days' => $unpaidDays,
                'reserved_days' => (! $asDraft && $policy->annual_quota !== null) ? $days : 0, 'reason' => $data['reason'],
                'contact_address' => $data['contact_address'] ?? null,
                'status' => $asDraft ? 'Draft' : 'Pending', 'approval_level' => 1,
                'substitute_id' => $data['substitute_id'] ?? null, 'substitute_status' => isset($data['substitute_id']) ? 'Pending' : null,
                'substitute_reason' => $data['substitute_reason'] ?? null,
                'meta_data' => $this->metaData($data),
                'signature_path' => $data['signature_path'] ?? null, 'notified_supervisor_at' => $data['notified_supervisor_at'] ?? null,
                'verification_hash' => Str::random(40),
                'submitted_at' => $asDraft ? null : now(),
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
            if (! $asDraft && ! $asApproved) {
                $this->snapshotApprovalChain($leave);
            }

            if ($asApproved) {
                $this->completeApproval($leave);
            }
            $this->audit->log($leave, $asApproved ? 'created_approved' : ($asDraft ? 'draft_created' : 'created'), null, $leave->toArray());

            return $leave;
        }, 5);

        // ร่างยังไม่เข้าสู่กระบวนการ จึงไม่เปิด workflow instance
        if ($asDraft || $asApproved) {
            return $leave;
        }

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
     * แก้ไขร่างใบลา
     *
     * ร่างต้องแก้ได้จริง มิฉะนั้นกฎ "ลบได้เฉพาะร่าง" จะบังคับให้ผู้ใช้ลบแล้วกรอกใหม่ทั้งใบ
     * ทุกครั้งที่พิมพ์ผิดคำเดียว ซึ่งแย่กว่าเดิม
     *
     * คำนวณจำนวนวัน นโยบาย และการแบ่งวันที่ได้รับเงินเดือนใหม่ทุกครั้ง เพราะผู้ใช้อาจ
     * เปลี่ยนประเภทการลาหรือช่วงวันที่ · ยังคงเป็นร่างอยู่เหมือนเดิม จึงไม่ออกเลขที่หนังสือ
     * และไม่กันโควตา
     */
    public function updateDraft(Leave $leave, array $data, ?UploadedFile $attachment = null): Leave
    {
        return DB::transaction(function () use ($leave, $data, $attachment) {
            $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'Draft') {
                throw ValidationException::withMessages(['status' => 'แก้ไขได้เฉพาะร่างใบลาเท่านั้น']);
            }

            $user = User::with(['personnel', 'currentAssignment', 'personnelType', 'profile'])->findOrFail($locked->user_id);
            $before = $locked->toArray();

            [$policy, $start, $end, $period, $days] = $this->resolveRequest($user, $data, $attachment !== null || $locked->evidences()->exists());

            $overlap = Leave::where('user_id', $locked->user_id)
                ->whereKeyNot($locked->id)
                ->whereNotIn('status', ['Draft', 'Cancelled', 'Rejected', 'Voided'])
                ->where('start_date', '<=', $end)->where('end_date', '>=', $start)
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'มีใบลาในช่วงเวลานี้อยู่แล้ว']);
            }

            $balance = LeaveBalance::whereKey($this->policies->balance($user, $policy, $start)->id)->lockForUpdate()->first();
            [$paidDays, $unpaidDays] = $this->splitPaidDays($policy, $balance, $days);

            $locked->fill([
                'leave_type_id' => $policy->leave_type_id,
                'leave_policy_id' => $policy->id,
                'fiscal_year_id' => $this->policies->fiscalYear($start)?->id,
                'start_date' => $start,
                'end_date' => $end,
                'leave_period' => $period,
                'total_days' => $days,
                'paid_days' => $paidDays,
                'unpaid_days' => $unpaidDays,
                'reason' => $data['reason'],
                'contact_address' => $data['contact_address'] ?? null,
                'substitute_id' => $data['substitute_id'] ?? null,
                'substitute_status' => isset($data['substitute_id']) ? 'Pending' : null,
                'substitute_reason' => $data['substitute_reason'] ?? null,
                'meta_data' => $this->metaData($data),
            ]);

            // ลายเซ็นใหม่มาเมื่อผู้ใช้วาดใหม่เท่านั้น ไม่งั้นคงของเดิมไว้
            if (! empty($data['signature_path'])) {
                $locked->signature_path = $data['signature_path'];
            }

            $locked->save();

            /*
             * แนบไฟล์ใหม่ = แทนที่ของเดิม ร่างมีหลักฐานได้ชุดเดียวเสมอ
             * มิฉะนั้นแก้ร่างสิบครั้งจะได้ไฟล์แนบสิบไฟล์ติดไปกับใบลาตอนยื่นจริง
             */
            if ($attachment !== null) {
                $locked->evidences()->delete();
                LeaveEvidence::create([
                    'leave_id' => $locked->id, 'uploaded_by' => $locked->user_id,
                    'evidence_type' => $policy->required_evidence_type ?: 'supporting_document',
                    'original_name' => $attachment->getClientOriginalName(),
                    'storage_path' => Storage::disk('local')->putFile('private/leave-evidences', $attachment),
                    'mime_type' => $attachment->getMimeType(), 'file_size' => $attachment->getSize(),
                    'sha256' => hash_file('sha256', $attachment->getRealPath()),
                ]);
            }

            $this->audit->log($locked, 'draft_updated', $before, $locked->toArray());

            return $locked;
        }, 5);
    }

    /**
     * ตรวจและคำนวณคำขอลาหนึ่งใบจากข้อมูลที่กรอกมา
     *
     * ใช้ร่วมกันระหว่างการสร้างใบลาและการแก้ไขร่าง เพื่อให้ทั้งสองทางตรวจด้วยเกณฑ์
     * ชุดเดียวกันเสมอ — ร่างที่แก้แล้วจึงส่งอนุมัติได้แน่นอน ไม่ใช่ผ่านตอนแก้แต่ตกตอนส่ง
     *
     * @return array{0: LeavePolicy, 1: Carbon, 2: Carbon, 3: string, 4: float}
     */
    private function resolveRequest(User $user, array $data, bool $hasEvidence): array
    {
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $policy = $this->policies->resolve($user, (int) $data['leave_type_id'], $start);
        $period = $data['leave_period'] ?? 'full_day';

        $days = $period === 'full_day'
            ? $this->calculateTotalDays($start, $end, $policy->exclude_holidays, $user->department_id, $user)
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

        $this->policies->assertEligible($user, $policy, $start, $end, $days, $period, $hasEvidence);
        // เงื่อนไขเฉพาะประเภทลาตามระเบียบ เช่น ลาอุปสมบทได้ครั้งเดียวตลอดอายุราชการ
        $this->eligibilityRules->assert($user, $policy, $start);

        return [$policy, $start, $end, $period, $days];
    }

    /** เลขที่หนังสือลำดับถัดไปของประเภทการลานี้ในรอบปีนั้น */
    private function nextDocumentNumber(LeavePolicy $policy, Carbon $start): string
    {
        $type = LeaveType::findOrFail($policy->leave_type_id);

        return $this->numbers->next($type->code_prefix ?: 'L', $this->policies->periodYear($policy, $start) + 543);
    }

    /**
     * ผนึกสายอนุมัติ ณ วันที่ยื่นจริง
     *
     * ต้องคัดลอก "วิธีหาตัวผู้อนุมัติ" ให้ครบ ไม่ใช่แค่ role เพราะขั้นตอนแบบหัวหน้าแผนก
     * ต้นสังกัด/ผอ./ผู้ใช้เจาะจง ไม่ได้ผูกกับ role ใด ๆ ถ้าตกหล่นจะกลายเป็นขั้นตอนแบบ
     * ROLE ทั้งหมด แล้วผู้อนุมัติตัวจริงจะถูกปฏิเสธสิทธิ์ในขั้นของตัวเอง
     */
    private function snapshotApprovalChain(Leave $leave): void
    {
        foreach (ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->load('role') as $step) {
            LeaveApproval::firstOrCreate(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => $step->step_order], [
                'step_name' => $step->name, 'required_role_id' => $step->role_id, 'status' => 'Pending',
                'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                'specific_user_id' => $step->specific_user_id, 'permission_name' => $step->permission_name,
            ]);
        }
    }

    /**
     * ส่งร่างเข้าสายอนุมัติ — จุดที่ใบลากลายเป็นเอกสารของทางราชการ
     *
     * ทุกอย่างที่ร่างจงใจไม่ทำ เกิดขึ้นพร้อมกันที่นี่: ออกเลขที่หนังสือ กันโควตา
     * ผนึกสายอนุมัติ และเปิด workflow instance
     *
     * ตรวจสิทธิ์และเงื่อนไขซ้ำอีกรอบ เพราะร่างอาจถูกบันทึกไว้นานจนเงื่อนไขเปลี่ยน
     * (โควตาหมดไปแล้ว มีใบลาช่วงเดียวกันแทรกเข้ามา หรือเลยกำหนดยื่นล่วงหน้า)
     */
    public function submitDraft(Leave $leave): Leave
    {
        $submitted = DB::transaction(function () use ($leave) {
            $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'Draft') {
                throw ValidationException::withMessages(['status' => 'ใบลานี้ถูกส่งเข้าสายอนุมัติไปแล้ว']);
            }

            $user = User::with(['personnel', 'currentAssignment', 'personnelType', 'profile'])->lockForUpdate()->findOrFail($locked->user_id);
            $start = Carbon::parse($locked->start_date);
            $end = Carbon::parse($locked->end_date);
            $policy = $this->policies->resolve($user, (int) $locked->leave_type_id, $start);
            $period = $locked->leave_period ?: 'full_day';

            $this->policies->assertEligible($user, $policy, $start, $end, (float) $locked->total_days, $period, $locked->evidences()->exists());
            $this->eligibilityRules->assert($user, $policy, $start);

            $overlap = Leave::where('user_id', $locked->user_id)
                ->whereKeyNot($locked->id)
                ->whereNotIn('status', ['Draft', 'Cancelled', 'Rejected', 'Voided'])
                ->where('start_date', '<=', $end)->where('end_date', '>=', $start)
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'มีใบลาในช่วงเวลานี้อยู่แล้ว']);
            }

            $balance = $this->policies->balance($user, $policy, $start);
            $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
            $available = (float) $balance->quota + (float) $balance->carried_forward - (float) $balance->used - (float) $balance->reserved;
            if ($policy->annual_quota !== null && $available < (float) $locked->total_days) {
                throw ValidationException::withMessages(['leave_type_id' => 'วันลาคงเหลือไม่เพียงพอ (รวมรายการรออนุมัติแล้ว)']);
            }

            [$paidDays, $unpaidDays] = $this->splitPaidDays($policy, $balance, (float) $locked->total_days);
            $before = $locked->toArray();

            $locked->fill([
                'leave_policy_id' => $policy->id,
                'document_number' => $locked->document_number ?: $this->nextDocumentNumber($policy, $start),
                'paid_days' => $paidDays,
                'unpaid_days' => $unpaidDays,
                'reserved_days' => $policy->annual_quota !== null ? (float) $locked->total_days : 0,
                'status' => 'Pending',
                'approval_level' => 1,
                'submitted_at' => now(),
            ])->save();

            if ($locked->reserved_days > 0) {
                $balance->reserved += (float) $locked->reserved_days;
                $balance->leave_policy_id = $policy->id;
                $balance->save();
            }

            $this->snapshotApprovalChain($locked);
            $this->audit->log($locked, 'submitted', $before, $locked->toArray());

            return $locked;
        }, 5);

        if (config('workflow.shadow_mode', true)) {
            try {
                $this->workflow->prepare($submitted);
            } catch (\Throwable $exception) {
                Log::warning('เปิด workflow instance ให้ใบลาไม่สำเร็จ', [
                    'leave_id' => $submitted->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $submitted;
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

    /** ผลของการอนุมัติที่ใช้ร่วมกันทั้งสายอนุมัติและการบันทึกแทน */
    public function completeApproval(Leave $leave): void
    {
        // ผู้เรียกต้องถือ lock ของใบลา (หรือเพิ่งสร้างใบลา) ภายใน transaction เดียวกัน
        abort_unless($this->deductBalance($leave), 422, 'วันลาคงเหลือไม่เพียงพอ');
        $leave->update(['status' => 'Approved']);
        app(LeaveAttendanceSync::class)->apply($leave->load(['type', 'user', 'policy']));
    }

    /**
     * ตัดวันลาจริงตอนอนุมัติขั้นสุดท้าย — คืน false เมื่อโควตาไม่พอ ผู้เรียกต้องยกเลิกทั้งรายการ
     *
     * ประเภทการลาที่ระเบียบ **ไม่จำกัดจำนวนวัน** (`annual_quota` เป็น null) ต้องข้ามการตรวจโควตา
     * เหมือนตอนยื่น มิฉะนั้นจะคิด available = 0 แล้วปฏิเสธทุกใบ — ลาเข้ารับการตรวจเลือก
     * ลาไปศึกษาต่อ ลาไปปฏิบัติงานในองค์การระหว่างประเทศ ลาติดตามคู่สมรส และลาไปฟื้นฟูสมรรถภาพ
     * จึงอนุมัติไม่ผ่านเลยสักใบ ค้างสถานะรออนุมัติตลอดไป
     *
     * ยังบันทึก `used` ให้ตามปกติ เพราะรายงานและทะเบียนคุมการลาต้องรู้ว่าใช้ไปกี่วัน
     */
    public function deductBalance(Leave $leave): bool
    {
        return DB::transaction(function () use ($leave) {
            $balance = $this->balanceFor($leave, true);
            if (! $balance) {
                return false;
            }
            $reserved = min((float) $balance->reserved, (float) $leave->reserved_days);
            $unlimited = $this->isUnlimited($leave, $balance);
            $available = (float) $balance->quota + (float) $balance->carried_forward - (float) $balance->used - ((float) $balance->reserved - $reserved);
            if (! $unlimited && $available < $leave->total_days) {
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

    /**
     * ประเภทการลานี้ไม่จำกัดจำนวนวันตามระเบียบหรือไม่
     *
     * ถามนโยบายของใบลาก่อน ถ้าใบลาไม่ได้ผูกนโยบายไว้จึงถามนโยบายที่ผูกกับแถวโควตา
     * — แถวเก่าที่ย้ายข้อมูลมาบางแถวไม่มีทั้งคู่ กรณีนั้นถือว่าจำกัดตามตัวเลขในแถวเหมือนเดิม
     */
    private function isUnlimited(Leave $leave, LeaveBalance $balance): bool
    {
        $policy = $leave->policy ?? $balance->policy;

        return $policy !== null && $policy->annual_quota === null;
    }

    private function balanceFor(Leave $leave, bool $lock): ?LeaveBalance
    {
        $year = $this->policies->periodYearForLeave($leave);
        $query = LeaveBalance::where('user_id', $leave->user_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $year);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }
}
