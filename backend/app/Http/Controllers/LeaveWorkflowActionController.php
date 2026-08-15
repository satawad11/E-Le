<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveActionLog;
use Illuminate\Http\Request;

class LeaveWorkflowActionController extends Controller
{
    public function hrVerify(Request $request, Leave $leave)
    {
        $this->authorize('hrVerify', $leave);
        $leave->hr_verified_by = $request->user()->id;
        $leave->hr_verified_at = now();
        $leave->save();
        $this->log($leave, $request, 'HR Verified');

        return response()->json(['message' => 'ตรวจสอบสถิติการลาเรียบร้อยแล้ว']);
    }

    public function acceptSubstitute(Request $request, Leave $leave)
    {
        return $this->respondAsSubstitute($request, $leave, 'Accepted', 'Substitute Accepted', 'รับทราบการมอบหมายงานเรียบร้อยแล้ว');
    }

    public function rejectSubstitute(Request $request, Leave $leave)
    {
        return $this->respondAsSubstitute($request, $leave, 'Rejected', 'Substitute Rejected', 'ปฏิเสธการรับมอบหมายงานเรียบร้อยแล้ว');
    }

    private function respondAsSubstitute(Request $request, Leave $leave, string $status, string $action, string $message)
    {
        if ($leave->substitute_id !== $request->user()->id) {
            return response()->json(['message' => 'ไม่มีสิทธิ์'], 403);
        }
        $leave->substitute_status = $status;
        $leave->save();
        $this->log($leave, $request, $action);

        return response()->json(['message' => $message]);
    }

    private function log(Leave $leave, Request $request, string $action): void
    {
        LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $request->user()->id, 'action' => $action]);
    }
}
