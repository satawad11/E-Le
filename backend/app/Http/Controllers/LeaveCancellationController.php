<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Http\Request;

class LeaveCancellationController extends Controller
{
    public function __construct(
        private LeaveService $leaveService,
        private ApproverResolverService $resolver,
    ) {}

    public function cancel(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isAdminOrHR = $user->can('leave.management.manage') || $user->can('system.user.view');
        if ($leave->user_id !== $user->id && ! $isAdminOrHR) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ยกเลิกใบลานี้'], 403);
        }
        if ($leave->status === 'Pending') {
            $this->leaveService->restoreBalance($leave);
            $leave->status = 'Cancelled';
            $leave->save();
            LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Cancelled', 'details' => ['reason' => 'User cancelled pending leave']]);

            return response()->json(['message' => 'ยกเลิกใบลาเรียบร้อยแล้ว']);
        }
        if ($leave->status === 'Approved') {
            $request->validate(['cancellation_reason' => 'required|string|max:1000']);
            $leave->cancellation_status = 'Pending';
            $leave->cancellation_reason = $request->cancellation_reason;
            $leave->cancellation_date = now();
            $firstStep = ApprovalStep::orderBy('step_order')->first();
            $leave->approval_level = $firstStep ? $firstStep->step_order : 1;
            $leave->save();
            LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Cancellation Requested', 'details' => ['reason' => $request->cancellation_reason]]);

            return response()->json(['message' => 'ส่งคำขอยกเลิกใบลาที่อนุมัติแล้วเรียบร้อย กรุณารอการอนุมัติยกเลิก']);
        }

        return response()->json(['message' => 'ไม่สามารถยกเลิกใบลานี้ได้'], 422);
    }

    public function approve(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isSuperAdmin = $user->can('leave.management.manage');
        if ($leave->cancellation_status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่มีคำขอยกเลิกที่รออนุมัติ'], 422);
        }
        if (! $isSuperAdmin && $leave->user_id === $user->id) {
            return response()->json(['message' => 'ห้ามอนุมัติคำขอยกเลิกใบลาของตนเอง'], 403);
        }
        if (! $isSuperAdmin && ! $this->canActAtCurrentStep($user, $leave)) {
            return response()->json(['message' => 'ไม่มีสิทธิ์อนุมัติในระดับนี้'], 403);
        }
        $nextStep = $isSuperAdmin ? null : ApprovalStep::where('step_order', '>', $leave->approval_level)->orderBy('step_order')->first();
        if (! $nextStep) {
            $leave->cancellation_status = 'Approved';
            $leave->status = 'Cancelled';
            $this->leaveService->restoreBalance($leave);
            $action = 'Cancellation Approved';
        } else {
            $leave->approval_level = $nextStep->step_order;
            $action = 'Cancellation Step Approved';
        }
        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $user->id,
            'action' => $action,
            'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
        ]);
        $leave->save();

        return response()->json(['message' => 'อนุมัติการยกเลิกใบลาเรียบร้อยแล้ว']);
    }

    public function reject(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isSuperAdmin = $user->can('leave.management.manage');
        if ($leave->cancellation_status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่มีคำขอยกเลิกที่รออนุมัติ'], 422);
        }
        if (! $isSuperAdmin && $leave->user_id === $user->id) {
            return response()->json(['message' => 'ห้ามปฏิเสธคำขอยกเลิกใบลาของตนเอง'], 403);
        }
        if (! $isSuperAdmin && ! $this->canActAtCurrentStep($user, $leave)) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ปฏิเสธในระดับนี้'], 403);
        }
        $leave->cancellation_status = 'Rejected';
        $leave->save();
        LeaveActionLog::create([
            'leave_id' => $leave->id,
            'user_id' => $user->id,
            'action' => 'Cancellation Rejected',
            'details' => $request->has('comment') && $request->comment ? ['comment' => $request->comment] : null,
        ]);

        return response()->json(['message' => 'ปฏิเสธการยกเลิกใบลาเรียบร้อยแล้ว']);
    }

    /**
     * ใช้เกณฑ์เดียวกับการอนุมัติใบลา — snapshot ของใบนั้นก่อน แล้วค่อยถอยไปสายอนุมัติปัจจุบัน
     *
     * เดิมที่นี่เทียบ `approval_steps.role_id` กับ role ของผู้ใช้ตรง ๆ จึงไม่รู้จัก
     * ขั้นตอนแบบหัวหน้าแผนกต้นสังกัด/ผอ./ผู้ใช้เจาะจง และไม่รองรับการมอบอำนาจ
     * ผู้อนุมัติตัวจริงจึงกดอนุมัติคำขอยกเลิกไม่ได้ ทั้งที่เห็นรายการอยู่ตรงหน้า
     */
    private function canActAtCurrentStep(User $user, Leave $leave): bool
    {
        $step = LeaveApproval::where('leave_id', $leave->id)
            ->where('step_order', $leave->approval_level)
            ->first()
            ?? ApprovalStep::where('step_order', $leave->approval_level)->first();

        if ($step === null || $leave->user === null) {
            return false;
        }

        return $this->resolver->isAuthorizedApprover($user, $leave->user, $step);
    }
}
