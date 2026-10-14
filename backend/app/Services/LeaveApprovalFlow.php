<?php

namespace App\Services;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
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
 */
class LeaveApprovalFlow
{
    public function __construct(private ApproverResolverService $resolver) {}

    public function stepAt(Leave $leave, int $order): LeaveApproval|ApprovalStep|null
    {
        $snapshot = $leave->relationLoaded('approvals')
            ? $leave->approvals->firstWhere('step_order', $order)
            : $leave->approvals()->where('step_order', $order)->first();

        return $snapshot ?? ApprovalStep::where('step_order', $order)->first();
    }

    public function currentStep(Leave $leave): LeaveApproval|ApprovalStep|null
    {
        return $this->stepAt($leave, (int) $leave->approval_level);
    }

    public function firstStepOrder(Leave $leave): int
    {
        return (int) ($leave->approvals()->min('step_order') ?? ApprovalStep::min('step_order') ?? 1);
    }

    public function nextStepOrder(Leave $leave, int $after): ?int
    {
        $next = $leave->approvals()->where('step_order', '>', $after)->min('step_order');
        if ($next === null && ! $leave->approvals()->exists()) {
            $next = ApprovalStep::where('step_order', '>', $after)->min('step_order');
        }

        return $next === null ? null : (int) $next;
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
        if (! $this->resolver->canSelfApprove($leave->user, $step)) {
            $ids = array_diff($ids, [$leave->user_id]);
        }

        return User::whereIn('id', $ids)->where('is_active', true)->get();
    }
}
