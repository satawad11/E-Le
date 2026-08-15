<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Services\LeaveService;
use Illuminate\Http\Request;

class LeaveCancellationController extends Controller
{
    public function __construct(private LeaveService $leaveService) {}

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
        if (! $isSuperAdmin && ! $this->canActAtCurrentStep($user->roles->pluck('id')->unique()->values()->all(), $leave)) {
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
        if (! $user->can('leave.management.manage') && ! $this->canActAtCurrentStep($user->roles->pluck('id')->unique()->values()->all(), $leave)) {
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

    private function canActAtCurrentStep(array $roleIds, Leave $leave): bool
    {
        $currentStep = ApprovalStep::where('step_order', $leave->approval_level)->first();

        return $currentStep !== null && in_array($currentStep->role_id, $roleIds);
    }
}
