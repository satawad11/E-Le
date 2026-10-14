<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\Workflow\ApproverResolverService;
use App\Services\Workflow\LeaveWorkflowShadow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernmentApprovalController extends Controller
{
    public function __construct(
        private LeaveService $service,
        private LeaveWorkflowShadow $shadow,
        private NotificationCenter $notifications,
        private LeaveRequestNotificationService $leaveNotifications,
    ) {}

    /**
     * LEGACY-05 · 05-3 — Shadow mode
     *
     * ให้ workflow engine ใหม่คำนวณคู่ขนานแล้วบันทึกผลเทียบไว้
     * **ตรรกะเดิมด้านล่างยังเป็น source of truth ทั้งหมด** — ทุกอย่างในนี้
     * ถูกห่อ try/catch ไว้แล้วในตัว shadow เอง จึงไม่มีทางทำให้การอนุมัติจริงล้ม
     *
     * ถอดออกได้ทันทีด้วย config `workflow.shadow_mode` โดยไม่ต้อง revert โค้ด
     */
    private function shadowPrepare(Leave $leave): ?int
    {
        return config('workflow.shadow_mode', true) ? $this->shadow->prepare($leave) : null;
    }

    private function shadow(Leave $leave, string $action, Request $request, ?string $comment, ?int $instanceId): void
    {
        if (! config('workflow.shadow_mode', true)) {
            return;
        }

        $this->shadow->mirrorAction($leave, $action, $request->user(), $comment, $instanceId);
    }

    /**
     * แจ้งเตือนผู้ยื่นใบลาทุกช่องทาง — ห้ามให้ช่องทางภายนอกล้มแล้วทำให้คำขออนุมัติพังตาม
     */
    private function notify(Leave $leave, string $status, Request $request): void
    {
        $this->notifications->emit(
            $status === 'Approved' ? 'leave.approved' : 'leave.rejected',
            [$leave->user],
            $leave,
            ['status' => strtolower($status)],
            $request->user(),
        );
    }

    private function step(Leave $leave): LeaveApproval
    {
        $snapshot = $leave->approvals()->where('step_order', $leave->approval_level)->first();
        if ($snapshot) {
            return $snapshot;
        }$legacy = ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->firstWhere('step_order', $leave->approval_level);
        abort_unless($legacy, 422, ApprovalStep::query()->chain(ApprovalStep::CHAIN_LEAVE)->exists() ? 'ไม่พบขั้นตอนอนุมัติลำดับที่ '.$leave->approval_level.' กรุณาตรวจสอบการตั้งค่าลำดับการอนุมัติ' : 'ยังไม่ได้ตั้งค่าลำดับการอนุมัติในระบบ กรุณาตั้งค่าที่เมนูจัดการลำดับการอนุมัติก่อน');

        // ใบลาที่ยื่นก่อนมี snapshot — สร้างย้อนหลังจากสายอนุมัติปัจจุบัน โดยคัดลอกวิธีหาตัวผู้อนุมัติมาให้ครบ
        return LeaveApproval::firstOrCreate(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => $legacy->step_order], [
            'step_name' => $legacy->name, 'required_role_id' => $legacy->role_id, 'status' => 'Pending',
            'approver_type' => $legacy->approver_type ?: ApprovalStep::RESOLVER_ROLE,
            'specific_user_id' => $legacy->specific_user_id, 'permission_name' => $legacy->permission_name,
            'allow_self_approval' => (bool) $legacy->allow_self_approval,
        ]);
    }

    private function authorizeStep(Request $r, LeaveApproval $step, Leave $leave): ?int
    {
        /*
         * LEAVE-APPROVAL-02 — ใบลาของตนเองอนุมัติได้เฉพาะขั้นที่เปิด allow_self_approval
         * และตนเป็นผู้อนุมัติของขั้นนั้นโดยตรง ต้องตรวจก่อนทางลัดของผู้ดูแลด้านล่าง
         * ไม่งั้นผู้ถือ leave.management.manage จะอนุมัติทุกขั้นของใบลาตนเองได้
         */
        if ($leave->user_id === $r->user()->id) {
            abort_unless(
                app(ApproverResolverService::class)->canSelfApprove($leave->user, $step),
                403,
                'ขั้นตอนนี้ไม่อนุญาตให้อนุมัติใบลาของตนเอง'
            );

            return null;
        }

        if ($r->user()->can('leave.management.manage') || $r->user()->roles->contains('name', 'Super Admin')) {
            return null;
        }

        $resolver = app(ApproverResolverService::class);
        $isAuthorized = $resolver->isAuthorizedApprover($r->user(), $leave->user, $step);
        abort_unless($isAuthorized, 403, 'ไม่มีสิทธิ์อนุมัติในขั้นตอนนี้');

        return $resolver->getDelegatedFromId($r->user(), $leave->user, $step);
    }

    public function approve(Request $r, Leave $leave)
    {
        $data = $r->validate(['comment' => 'nullable|string|max:1000']);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        if ($leave->substitute_id) {
            abort_unless($leave->substitute_status === 'Accepted', 422, 'ผู้ปฏิบัติงานแทนยังไม่ตอบรับ');
        }$shadowInstanceId = $this->shadowPrepare($leave);
        DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $step = $this->step($locked);
            $delegatedFrom = $this->authorizeStep($r, $step, $locked);
            $selfApproval = $locked->user_id === $r->user()->id;
            $step->update(['approver_id' => $r->user()->id, 'approver_name' => $r->user()->full_name_with_title, 'approver_position' => $r->user()->profile?->position, 'signature_path' => $r->user()->profile?->signature_path, 'status' => 'Approved', 'comment' => $data['comment'] ?? null, 'approved_at' => now(), 'acted_at' => now(), 'delegated_from_user_id' => $delegatedFrom, 'is_self_approval' => $selfApproval]);
            $next = $locked->approvals()->where('step_order', '>', $step->step_order)->orderBy('step_order')->first();
            if ($next) {
                $locked->update(['approval_level' => $next->step_order]);
            } else {
                abort_unless($this->service->deductBalance($locked), 422, 'วันลาคงเหลือไม่เพียงพอ');
                $locked->update(['status' => 'Approved']);
            }LeaveAuditLog::create(['leave_id' => $locked->id, 'user_id' => $r->user()->id, 'action' => 'approval_approved', 'after' => ['step' => $step->step_order, 'final' => ! $next, 'self_approved' => $selfApproval], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        }, 5);
        $leave->refresh();
        $this->shadow($leave, 'approve', $r, $data['comment'] ?? null, $shadowInstanceId);
        if ($leave->status === 'Approved') {
            $this->notify($leave, 'Approved', $r);
        } else {
            // เลื่อนไปขั้นถัดไป — แจ้งผู้มีสิทธิ์ของขั้นนั้น (เดิมไม่มีใครได้รับแจ้ง)
            $this->leaveNotifications->notifyCurrentStep($leave->load('user'), 'leave.approval_requested', $r->user());
        }

        return response()->json(['message' => 'อนุมัติเรียบร้อย', 'status' => $leave->status]);
    }

    public function reject(Request $r, Leave $leave)
    {
        $data = $r->validate(['comment' => 'required|string|max:1000']);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        abort_if($leave->user_id === $r->user()->id, 403, 'ห้ามปฏิเสธใบลาของตนเอง');
        $shadowInstanceId = $this->shadowPrepare($leave);
        DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $step = $this->step($locked);
            $delegatedFrom = $this->authorizeStep($r, $step, $locked);
            $step->update(['approver_id' => $r->user()->id, 'approver_name' => $r->user()->full_name_with_title, 'approver_position' => $r->user()->profile?->position, 'signature_path' => $r->user()->profile?->signature_path, 'status' => 'Rejected', 'comment' => $data['comment'], 'approved_at' => now(), 'acted_at' => now(), 'delegated_from_user_id' => $delegatedFrom]);
            $this->service->restoreBalance($locked);
            $locked->update(['status' => 'Rejected']);
            LeaveAuditLog::create(['leave_id' => $locked->id, 'user_id' => $r->user()->id, 'action' => 'approval_rejected', 'after' => ['step' => $step->step_order, 'comment' => $data['comment']], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        }, 5);
        $leave->refresh();
        $this->shadow($leave, 'reject', $r, $data['comment'], $shadowInstanceId);
        $this->notify($leave, 'Rejected', $r);

        return response()->json(['message' => 'ปฏิเสธใบลาเรียบร้อย']);
    }
}
