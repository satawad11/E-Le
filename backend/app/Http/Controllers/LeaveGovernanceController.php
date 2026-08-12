<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveGovernanceController extends Controller
{
    public function void(Request $r, Leave $leave, LeaveService $service)
    {
        abort_unless($r->user()->can('leave.management.manage'), 403);
        $data = $r->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($r, $leave, $service, $data) {
            $before = $leave->toArray();
            $service->restoreBalance($leave);
            $leave->update(['status' => 'Voided', 'voided_by' => $r->user()->id, 'voided_at' => now(), 'void_reason' => $data['reason']]);
            $leave->delete();
            LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $r->user()->id, 'action' => 'voided', 'before' => $before, 'after' => $leave->toArray(), 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        });

        return response()->json(['message' => 'ยกเลิกเอกสารโดยคงประวัติเรียบร้อย']);
    }

    public function audit(Request $r, Leave $leave)
    {
        abort_unless($leave->user_id === $r->user()->id || $r->user()->can('leave.management.manage'), 403);

        return LeaveAuditLog::where('leave_id', $leave->id)->orderByDesc('created_at')->paginate(100);
    }
}
