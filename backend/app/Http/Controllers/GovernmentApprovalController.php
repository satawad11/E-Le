<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Services\LeaveService;
use App\Services\NotificationCenter\NotificationCenter;
use App\Services\Workflow\LeaveApprovalCoordinator;
use App\Services\Workflow\LeaveWorkflowShadow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernmentApprovalController extends Controller
{
    public function __construct(
        private LeaveService $service,
        private LeaveApprovalCoordinator $coordinator,
        private LeaveWorkflowShadow $shadow,
        private NotificationCenter $notifications,
    ) {}

    /**
     * Shadow mode (LEGACY-05 · 05-3)
     *
     * ให้ workflow engine ใหม่คำนวณคู่ขนานแล้วบันทึกผลเทียบไว้ ตรรกะเดิมยังตัดสินจริง
     * ทุกอย่างในนี้ถูกห่อ try/catch ไว้แล้วในตัว shadow เอง จึงไม่ทำให้การอนุมัติจริงล้ม
     *
     * CLEANUP-01 (D4) — เมื่อ engine เป็นผู้ตัดสินแล้ว (`engine_is_source_of_truth`)
     * ไม่ต้องเดิน shadow อีก เพราะ engine เดินของจริงไปแล้วในคำสั่งเดียวกัน
     */
    private function shadowEnabled(): bool
    {
        return config('workflow.shadow_mode', true) && ! $this->coordinator->engineIsSourceOfTruth();
    }

    private function shadowPrepare(Leave $leave): ?int
    {
        return $this->shadowEnabled() ? $this->shadow->prepare($leave) : null;
    }

    private function shadow(Leave $leave, string $action, Request $request, ?string $comment, ?int $instanceId): void
    {
        if (! $this->shadowEnabled()) {
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

    /**
     * บันทึกผลของขั้นที่เพิ่งกระทำ — เขียนเหมือนกันทุกกรณี ไม่ว่าใครเป็นผู้ตัดสิน
     */
    private function recordStep(LeaveApproval $step, Request $request, string $status, ?string $comment, ?int $delegatedFrom): void
    {
        $actor = $request->user();

        $step->update([
            'approver_id' => $actor->id,
            'approver_name' => $actor->full_name_with_title,
            'approver_position' => $actor->profile?->position,
            'signature_path' => $actor->profile?->signature_path,
            'status' => $status,
            'comment' => $comment,
            'approved_at' => now(),
            'acted_at' => now(),
            'delegated_from_user_id' => $delegatedFrom,
        ]);
    }

    public function approve(Request $r, Leave $leave)
    {
        $data = $r->validate(['comment' => 'nullable|string|max:1000']);
        abort_unless($leave->status === 'Pending', 422, 'ใบลาไม่ได้อยู่ระหว่างอนุมัติ');
        abort_if($leave->user_id === $r->user()->id, 403, 'ห้ามอนุมัติใบลาของตนเอง');

        if ($leave->substitute_id) {
            abort_unless($leave->substitute_status === 'Accepted', 422, 'ผู้ปฏิบัติงานแทนยังไม่ตอบรับ');
        }

        $shadowInstanceId = $this->shadowPrepare($leave);

        DB::transaction(function () use ($r, $leave, $data) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $decision = $this->coordinator->decide($locked, 'approve', $r->user(), $data['comment'] ?? null);

            $this->recordStep($decision['step'], $r, 'Approved', $data['comment'] ?? null, $decision['delegated_from']);

            if (! $decision['final']) {
                $locked->update(['approval_level' => $decision['next_step_order']]);
            } else {
                abort_unless($this->service->deductBalance($locked), 422, 'วันลาคงเหลือไม่เพียงพอ');
                $locked->update(['status' => 'Approved']);
            }

            LeaveAuditLog::create([
                'leave_id' => $locked->id,
                'user_id' => $r->user()->id,
                'action' => 'approval_approved',
                'after' => ['step' => $decision['step']->step_order, 'final' => $decision['final']],
                'ip_address' => $r->ip(),
                'user_agent' => $r->userAgent(),
            ]);
        }, 5);

        $leave->refresh();
        $this->shadow($leave, 'approve', $r, $data['comment'] ?? null, $shadowInstanceId);

        if ($leave->status === 'Approved') {
            $this->notify($leave, 'Approved', $r);
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
            $decision = $this->coordinator->decide($locked, 'reject', $r->user(), $data['comment']);

            $this->recordStep($decision['step'], $r, 'Rejected', $data['comment'], $decision['delegated_from']);
            $this->service->restoreBalance($locked);
            $locked->update(['status' => 'Rejected']);

            LeaveAuditLog::create([
                'leave_id' => $locked->id,
                'user_id' => $r->user()->id,
                'action' => 'approval_rejected',
                'after' => ['step' => $decision['step']->step_order, 'comment' => $data['comment']],
                'ip_address' => $r->ip(),
                'user_agent' => $r->userAgent(),
            ]);
        }, 5);

        $leave->refresh();
        $this->shadow($leave, 'reject', $r, $data['comment'], $shadowInstanceId);
        $this->notify($leave, 'Rejected', $r);

        return response()->json(['message' => 'ปฏิเสธใบลาเรียบร้อย']);
    }
}
