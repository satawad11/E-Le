<?php

namespace App\Services;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Support\Collection;

/**
 * ตอบคำถามเรื่องสายอนุมัติของใบลาใบหนึ่งจากที่เดียว
 *
 * ขั้นตอนของใบลาอ่านจาก snapshot ที่ pin ไว้ตอนยื่น (leave_approvals) ก่อนเสมอ
 * ถ้าใบเก่าไม่มี snapshot ค่อยถอยไปใช้ approval_steps ปัจจุบัน
 *
 * เดิมการอนุมัติใช้ snapshot + ApproverResolverService แต่การยกเลิกวันลาและ
 * รายการรออนุมัติใช้ approval_steps.role_id ตรง ๆ — ขั้นที่ตั้งเป็นหัวหน้าหน่วยงาน
 * ผู้อำนวยการ หรือรายบุคคลจึงไม่ขึ้นในรายการของผู้มีสิทธิ์ และอนุมัติยกเลิกไม่ได้
 *
 * ใบลามีสองสาย (PR #2 · LeaveApproval::STAGE_*): สายของการขอลา เดินด้วย approval_level
 * และสายของคำขอยกเลิกใบที่อนุมัติแล้ว เดินด้วย cancellation_approval_level
 * "ขั้นปัจจุบัน" จึงขึ้นกับว่าใบลากำลังรอพิจารณาเรื่องไหน
 */
class LeaveApprovalFlow
{
    public function __construct(private ApproverResolverService $resolver) {}

    /** ใบลานี้กำลังรอพิจารณา "คำขอยกเลิก" อยู่หรือไม่ (สายอนุมัติของการขอลาจบไปแล้ว) */
    public function awaitsCancellation(Leave $leave): bool
    {
        return $leave->status === 'Approved' && $leave->cancellation_status === 'Pending';
    }

    /** ขั้นตอนของสายการขอลา — snapshot ตอนยื่นก่อน ใบเก่าที่ไม่มี snapshot ถอยไปใช้สายปัจจุบัน */
    public function stepAt(Leave $leave, int $order): LeaveApproval|ApprovalStep|null
    {
        $snapshot = $leave->relationLoaded('approvals')
            ? $leave->approvals->firstWhere('step_order', $order)
            : $leave->approvals()->where('step_order', $order)->first();

        return $snapshot ?? ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->firstWhere('step_order', $order);
    }

    /** ขั้นตอนของสายคำขอยกเลิก — snapshot ตอนขอยกเลิกก่อน ถ้าไม่มีถอยไปใช้สายยกเลิกปัจจุบัน */
    public function cancellationStepAt(Leave $leave, int $order): LeaveApproval|ApprovalStep|null
    {
        $snapshot = $leave->relationLoaded('cancellationApprovals')
            ? $leave->cancellationApprovals->firstWhere('step_order', $order)
            : $leave->cancellationApprovals()->where('step_order', $order)->first();

        return $snapshot ?? ApprovalStep::stepsFor(ApprovalStep::CHAIN_CANCELLATION)->firstWhere('step_order', $order);
    }

    public function currentStep(Leave $leave): LeaveApproval|ApprovalStep|null
    {
        if ($this->awaitsCancellation($leave)) {
            return $this->cancellationStepAt($leave, (int) ($leave->cancellation_approval_level ?: $leave->approval_level));
        }

        return $this->stepAt($leave, (int) $leave->approval_level);
    }

    /**
     * ผู้ใช้คนนี้ทำรายการในขั้นปัจจุบันของใบลานี้ได้หรือไม่ (ไม่รวมสิทธิ์ผู้ดูแลระบบ)
     *
     * ใบลาของตนเองผ่านได้เฉพาะขั้นที่เปิด allow_self_approval และตนเป็นผู้อนุมัติขั้นนั้นโดยตรง
     * — ApproverResolverService::isAuthorizedApprover() ตัดสินที่เดียว
     */
    public function canActAtCurrentStep(User $actor, Leave $leave): bool
    {
        $step = $this->currentStep($leave);

        return $step !== null && $leave->user !== null
            && $this->resolver->isAuthorizedApprover($actor, $leave->user, $step);
    }

    public function delegatedFromId(User $actor, Leave $leave): ?int
    {
        $step = $this->currentStep($leave);

        return $step && $leave->user ? $this->resolver->getDelegatedFromId($actor, $leave->user, $step) : null;
    }

    /** ผู้ที่ต้องดำเนินการในขั้นปัจจุบัน — ใช้ส่งแจ้งเตือน */
    public function currentApprovers(Leave $leave): Collection
    {
        $step = $this->currentStep($leave);
        if (! $step || ! $leave->user) {
            return collect();
        }
        $ids = $this->resolver->resolveEligibleApproverIds($leave->user, $step);

        // ผู้ทำหน้าที่แทนที่มีผลอยู่ (DELEGATION-01) — เดิมแจ้งเฉพาะผู้มีอำนาจตัวจริง
        // ผู้รักษาราชการแทนจึงไม่รู้ว่ามีใบลารอ ทั้งที่ผู้มีอำนาจไม่อยู่
        $delegateIds = UserDelegation::query()
            ->whereIn('user_id', array_values(array_diff($ids, [$leave->user_id])))
            ->where('delegate_id', '!=', $leave->user_id)
            ->inEffect()
            ->pluck('delegate_id')
            ->all();

        if (! $this->resolver->canSelfApprove($leave->user, $step)) {
            $ids = array_diff($ids, [$leave->user_id]);
        }

        return User::whereIn('id', array_values(array_unique(array_merge($ids, $delegateIds))))->where('is_active', true)->get();
    }
}
