<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveAuditLog;
use App\Services\LeaveApprovalFlow;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ยกเลิกวันลา (แบบใบขอยกเลิกวันลา)
 *
 * - ใบที่ยังรออนุมัติ: ผู้ยื่นยกเลิกเองได้ทันที คืนยอดที่จองไว้
 * - ใบที่อนุมัติแล้ว: ต้องยื่นคำขอยกเลิก แล้วผ่านสายอนุมัติเดียวกับตอนขอลา
 *   เมื่อขั้นสุดท้ายอนุมัติ ใบลาเป็น Cancelled และคืนวันลาที่ใช้ไป
 */
class LeaveCancellationController extends Controller
{
    public function __construct(
        private LeaveService $leaveService,
        private LeaveApprovalFlow $flow,
        private LeaveRequestNotificationService $notifications,
    ) {}

    public function cancel(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isAdminOrHR = $user->can('leave.management.manage') || $user->can('system.user.view');
        if ($leave->user_id !== $user->id && ! $isAdminOrHR) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ยกเลิกใบลานี้'], 403);
        }

        if ($leave->status === 'Pending') {
            DB::transaction(function () use ($leave, $user, $request) {
                $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === 'Pending', 422, 'สถานะใบลาเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');
                $this->leaveService->restoreBalance($locked);
                $locked->forceFill(['status' => 'Cancelled', 'cancellation_reason' => $request->input('cancellation_reason'), 'cancellation_date' => now()])->save();
                LeaveActionLog::create(['leave_id' => $locked->id, 'user_id' => $user->id, 'action' => 'Cancelled', 'details' => ['reason' => $request->input('cancellation_reason') ?: 'ยกเลิกใบลาที่ยังรออนุมัติ']]);
                $this->audit($request, $locked, 'cancelled_pending');
            });

            return response()->json(['message' => 'ยกเลิกใบลาเรียบร้อยแล้ว']);
        }

        if ($leave->status === 'Approved') {
            $request->validate(['cancellation_reason' => 'required|string|max:1000']);
            if ($leave->cancellation_status === 'Pending') {
                return response()->json(['message' => 'ใบลานี้มีคำขอยกเลิกที่รอพิจารณาอยู่แล้ว'], 422);
            }
            $leave->forceFill([
                'cancellation_status' => 'Pending',
                'cancellation_reason' => $request->cancellation_reason,
                'cancellation_date' => now(),
                'approval_level' => $this->flow->firstStepOrder($leave),
            ])->save();
            LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Cancellation Requested', 'details' => ['reason' => $request->cancellation_reason]]);
            $this->audit($request, $leave, 'cancellation_requested');
            $this->notifications->notifyCurrentStep($leave->load('user'), 'leave.cancellation_requested', $user);

            return response()->json(['message' => 'ส่งคำขอยกเลิกใบลาที่อนุมัติแล้วเรียบร้อย กรุณารอการอนุมัติยกเลิก']);
        }

        return response()->json(['message' => 'ไม่สามารถยกเลิกใบลานี้ได้'], 422);
    }

    public function approve(Request $request, Leave $leave)
    {
        $request->validate(['comment' => 'nullable|string|max:1000']);
        $user = $request->user();

        $result = DB::transaction(function () use ($request, $leave, $user) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertActionable($locked, $user);

            $isSelf = $locked->user_id === $user->id;
            // ทางลัดของผู้ดูแลใช้กับคำขอยกเลิกของตนเองไม่ได้ — ต้องเดินทีละขั้นเหมือนผู้อื่น
            $isAdmin = ! $isSelf && $user->can('leave.management.manage');
            $next = $isAdmin ? null : $this->flow->nextStepOrder($locked, (int) $locked->approval_level);

            if ($next === null) {
                // คืนวันลา **ก่อน** เปลี่ยนสถานะ — เดิมเปลี่ยนเป็น Cancelled ก่อน
                // restoreBalance จึงไม่รู้ว่าเป็นใบที่อนุมัติแล้ว และไม่คืนวันลาเลย
                $this->leaveService->restoreBalance($locked, true);
                $locked->forceFill(['cancellation_status' => 'Approved', 'status' => 'Cancelled'])->save();
                $action = 'Cancellation Approved';
            } else {
                $locked->forceFill(['approval_level' => $next])->save();
                $action = 'Cancellation Step Approved';
            }
            LeaveActionLog::create([
                'leave_id' => $locked->id, 'user_id' => $user->id, 'action' => $action,
                'details' => array_filter(['comment' => $request->comment, 'delegated_from' => $this->flow->delegatedFromId($user, $locked), 'self_approved' => $isSelf ?: null]) ?: null,
            ]);
            $this->audit($request, $locked, $next === null ? 'cancellation_approved' : 'cancellation_step_approved', ['comment' => $request->comment, 'self_approved' => $isSelf]);

            return [$locked, $next];
        }, 5);

        [$locked, $next] = $result;
        if ($next === null) {
            $this->notifications->notifyRequester($locked, 'leave.cancellation_approved', $user);
        } else {
            $this->notifications->notifyCurrentStep($locked, 'leave.cancellation_requested', $user);
        }

        return response()->json(['message' => $next === null ? 'อนุมัติการยกเลิกใบลาเรียบร้อยแล้ว คืนวันลาให้ผู้ยื่นแล้ว' : 'อนุมัติการยกเลิกในขั้นนี้แล้ว ส่งต่อขั้นถัดไป']);
    }

    public function reject(Request $request, Leave $leave)
    {
        $request->validate(['comment' => 'required|string|max:1000']);
        $user = $request->user();

        $locked = DB::transaction(function () use ($request, $leave, $user) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertActionable($locked, $user);
            $locked->forceFill(['cancellation_status' => 'Rejected'])->save();
            LeaveActionLog::create(['leave_id' => $locked->id, 'user_id' => $user->id, 'action' => 'Cancellation Rejected', 'details' => ['comment' => $request->comment]]);
            $this->audit($request, $locked, 'cancellation_rejected', ['comment' => $request->comment]);

            return $locked;
        }, 5);
        $this->notifications->notifyRequester($locked, 'leave.cancellation_rejected', $user);

        return response()->json(['message' => 'ปฏิเสธการยกเลิกใบลาเรียบร้อยแล้ว']);
    }

    /**
     * ต้องมีคำขอยกเลิกที่รอพิจารณาจริง และผู้ทำรายการต้องมีสิทธิ์ในขั้นปัจจุบัน
     *
     * เดิมไม่ตรวจ cancellation_status เลย — ผู้อนุมัติกด "อนุมัติยกเลิก" กับใบที่ยัง
     * รออนุมัติได้ ซึ่งไปเลื่อน approval_level ข้ามขั้นอนุมัติการลาของตนเองไป
     */
    private function assertActionable(Leave $leave, $user): void
    {
        abort_unless($leave->status === 'Approved' && $leave->cancellation_status === 'Pending', 422, 'ใบลานี้ไม่มีคำขอยกเลิกที่รอพิจารณา');
        if ($leave->user_id === $user->id) {
            // LEAVE-APPROVAL-02 — ของตนเองพิจารณาได้เฉพาะขั้นที่เปิด allow_self_approval และตนเป็นผู้อนุมัติขั้นนั้น
            abort_unless($this->flow->canActAtCurrentStep($user, $leave), 403, 'ขั้นตอนนี้ไม่อนุญาตให้พิจารณาคำขอยกเลิกวันลาของตนเอง');

            return;
        }
        abort_unless($user->can('leave.management.manage') || $this->flow->canActAtCurrentStep($user, $leave), 403, 'ไม่มีสิทธิ์พิจารณาในขั้นตอนนี้');
    }

    private function audit(Request $request, Leave $leave, string $action, array $after = []): void
    {
        LeaveAuditLog::create([
            'leave_id' => $leave->id, 'user_id' => $request->user()->id, 'action' => $action,
            'after' => $after + ['status' => $leave->status, 'cancellation_status' => $leave->cancellation_status, 'approval_level' => $leave->approval_level],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
