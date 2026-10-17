<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\LeaveApprovalSigner;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use App\Services\NotificationCenter\NotificationCenter;
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
        private LeaveApprovalSigner $signer,
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
        return LeaveApproval::firstOrCreate(
            ['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => $legacy->step_order],
            $legacy->snapshotAttributes() + ['status' => 'Pending'],
        );
    }

    /**
     * ฐานะการลงนามของผู้ทำรายการ (DELEGATION-01) — ดู LeaveApprovalSigner
     *
     * @return array{mode: string, signer: User, delegation: ?UserDelegation}
     */
    private function capacity(Request $r, LeaveApproval $step, Leave $leave, bool $isApprove): array
    {
        return $this->signer->capacity($r->user(), $leave, $step, $isApprove, $r->integer('on_behalf_of') ?: null);
    }

    public function approve(Request $r, Leave $leave)
    {
        $data = $r->validate([
            'comment' => 'nullable|string|max:1000',
            'signature_base64' => 'nullable|string|max:2000000',
            'on_behalf_of' => 'nullable|integer|exists:users,id',
        ]);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        if ($leave->substitute_id) {
            abort_unless($leave->substitute_status === 'Accepted', 422, 'ผู้ปฏิบัติงานแทนยังไม่ตอบรับ');
        }$shadowInstanceId = $this->shadowPrepare($leave);
        DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $step = $this->step($locked);
            $capacity = $this->capacity($r, $step, $locked, true);
            $selfApproval = $capacity['mode'] === LeaveApprovalSigner::MODE_SELF;
            $step->update(['status' => 'Approved'] + $this->signer->snapshot($capacity, $r->user(), $data['signature_base64'] ?? null, $data['comment'] ?? null));
            $next = $locked->approvals()->where('step_order', '>', $step->step_order)->orderBy('step_order')->first();
            if ($next) {
                $locked->update(['approval_level' => $next->step_order]);
            } else {
                abort_unless($this->service->deductBalance($locked), 422, 'วันลาคงเหลือไม่เพียงพอ');
                $locked->update(['status' => 'Approved']);
            }LeaveAuditLog::create(['leave_id' => $locked->id, 'user_id' => $r->user()->id, 'action' => 'approval_approved', 'after' => ['step' => $step->step_order, 'final' => ! $next, 'self_approved' => $selfApproval, 'capacity' => $capacity['mode'], 'signer_id' => $capacity['signer']->id, 'delegated_from' => $capacity['delegation']?->user_id], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
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
        $data = $r->validate([
            'comment' => 'required|string|max:1000',
            'on_behalf_of' => 'nullable|integer|exists:users,id',
        ]);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        abort_if($leave->user_id === $r->user()->id, 403, 'ห้ามปฏิเสธใบลาของตนเอง');
        $shadowInstanceId = $this->shadowPrepare($leave);
        DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $step = $this->step($locked);
            $capacity = $this->capacity($r, $step, $locked, false);
            $step->update(['status' => 'Rejected'] + $this->signer->snapshot($capacity, $r->user(), null, $data['comment']));
            $this->service->restoreBalance($locked);
            $locked->update(['status' => 'Rejected']);
            LeaveAuditLog::create(['leave_id' => $locked->id, 'user_id' => $r->user()->id, 'action' => 'approval_rejected', 'after' => ['step' => $step->step_order, 'comment' => $data['comment'], 'capacity' => $capacity['mode'], 'signer_id' => $capacity['signer']->id, 'delegated_from' => $capacity['delegation']?->user_id], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        }, 5);
        $leave->refresh();
        $this->shadow($leave, 'reject', $r, $data['comment'], $shadowInstanceId);
        $this->notify($leave, 'Rejected', $r);

        return response()->json(['message' => 'ปฏิเสธใบลาเรียบร้อย']);
    }
}
