<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\LeaveExternalForwardingService;
use Illuminate\Http\Request;

/**
 * งานบุคลากรบันทึกการส่งต่อใบลาที่เกินอำนาจสถานศึกษา และผลจากผู้มีอำนาจ (REDESIGN-2569 · D-01)
 */
class LeaveExternalForwardingController extends Controller
{
    public function __construct(private LeaveExternalForwardingService $forwarding) {}

    public function send(Request $request, Leave $leave)
    {
        abort_unless($request->user()->can('leave.management.manage'), 403);
        $data = $request->validate([
            'authority' => 'required|string|max:255',
            'dispatch_number' => 'required|string|max:100',
            'dispatch_date' => 'required|date|before_or_equal:today',
        ]);

        $this->forwarding->recordDispatch($leave, $request->user(), $data);

        return response()->json(['message' => 'บันทึกการส่งต่อผู้มีอำนาจแล้ว']);
    }

    public function result(Request $request, Leave $leave)
    {
        abort_unless($request->user()->can('leave.management.manage'), 403);
        $data = $request->validate([
            'result' => 'required|in:'.LeaveExternalForwardingService::APPROVED.','.LeaveExternalForwardingService::REJECTED,
            'result_number' => 'required|string|max:100',
            'result_date' => 'required|date|before_or_equal:today',
            'note' => 'nullable|string|max:1000',
        ]);

        $this->forwarding->recordResult($leave, $request->user(), $data);

        return response()->json(['message' => $data['result'] === LeaveExternalForwardingService::APPROVED
            ? 'บันทึกผลอนุญาตแล้ว'
            : 'บันทึกผลไม่อนุญาตแล้ว ระบบยกเลิกเอกสารและคืนวันลาให้แล้ว']);
    }
}
