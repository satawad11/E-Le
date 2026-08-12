<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAdjustment;
use Illuminate\Http\Request;

class LeaveGovernanceWorklistController extends Controller
{
    public function myLeaves(Request $request)
    {
        return Leave::withTrashed()->with(['type:id,name', 'evidences.verifier:id,name'])
            ->with(['approvals' => fn ($query) => $query->orderBy('step_order')])
            ->where('user_id', $request->user()->id)->latest('start_date')->paginate(50);
    }

    public function worklist(Request $request)
    {
        abort_unless($request->user()->can('leave.management.manage') || $request->user()->can('leave.approval.hr'), 403);
        $status = $request->validate(['status' => 'nullable|in:all,pending_evidence,pending_adjustment,overdue'])['status'] ?? 'all';
        $query = Leave::withTrashed()->with([
            'user:id,name,department_id', 'user.department:id,name', 'type:id,name',
            'evidences.uploader:id,name', 'evidences.verifier:id,name',
        ])->withCount([
            'evidences as pending_evidence_count' => fn ($q) => $q->where('verification_status', 'pending'),
        ]);
        if ($status === 'pending_evidence') {
            $query->whereHas('evidences', fn ($q) => $q->where('verification_status', 'pending'));
        }
        if ($status === 'pending_adjustment') {
            $query->whereHas('adjustments', fn ($q) => $q->where('status', 'Pending'));
        }
        if ($status === 'overdue') {
            $query->where('status', 'Pending')->where('submitted_at', '<', now()->subDays(3));
        }

        return $query->latest('submitted_at')->paginate(100);
    }

    public function adjustments(Request $request)
    {
        abort_unless($request->user()->can('leave.management.manage') || $request->user()->can('leave.approval.hr'), 403);

        return LeaveAdjustment::with(['leave.user:id,name,department_id', 'leave.user.department:id,name', 'leave.type:id,name'])
            ->where('status', $request->input('status', 'Pending'))->latest()->paginate(100);
    }
}
