<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\PersonnelType;
use App\Services\AuditLogger;
use App\Services\LeavePolicyService;
use Illuminate\Http\Request;

class LeavePolicyController extends Controller
{
    public function __construct(private LeavePolicyService $policies, private AuditLogger $audit) {}

    public function index(Request $r)
    {
        return LeavePolicy::with(['personnelType', 'leaveType'])->when($r->personnel_type_id, fn ($q, $v) => $q->where('personnel_type_id', $v))->orderByDesc('effective_from')->get();
    }

    public function personnelTypes()
    {
        return PersonnelType::where('is_active', true)->orderBy('id')->get();
    }

    public function store(Request $r)
    {
        $policy = LeavePolicy::create($this->validated($r));
        $this->audit->log('leave_policy.created', $policy, $policy->id, null, $policy->fresh()->toArray());

        return response()->json(['policy' => $policy->load(['personnelType', 'leaveType']), 'sync' => $this->policies->syncBalances($policy)], 201);
    }

    public function update(Request $r, LeavePolicy $policy)
    {
        // สิทธิ์วันลาเปลี่ยนแล้วยอดคงเหลือถูก sync ตาม ต้องย้อนดูได้ว่าใครแก้อะไร
        $before = $policy->toArray();
        $policy->update($this->validated($r));
        $this->audit->log('leave_policy.updated', $policy, $policy->id, $before, $policy->fresh()->toArray());

        return response()->json(['policy' => $policy->fresh(['personnelType', 'leaveType']), 'sync' => $this->policies->syncBalances($policy)]);
    }

    private function validated(Request $r): array
    {
        return $r->validate(['personnel_type_id' => 'required|exists:personnel_types,id', 'leave_type_id' => 'required|exists:leave_types,id', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'annual_quota' => 'nullable|numeric|min:0', 'max_days_per_request' => 'nullable|numeric|min:0', 'max_days_with_pay' => 'nullable|numeric|min:0', 'minimum_service_days' => 'integer|min:0', 'notice_period_days' => 'integer|min:0', 'allow_past_dates' => 'boolean', 'exclude_holidays' => 'boolean', 'allow_half_day' => 'boolean', 'requires_substitute' => 'boolean', 'attachment_required_after_days' => 'nullable|numeric|min:0', 'required_evidence_type' => 'nullable|string|max:80', 'is_carry_forward' => 'boolean', 'max_carry_forward' => 'numeric|min:0', 'max_accumulated' => 'nullable|numeric|min:0', 'period_basis' => 'required|in:fiscal_year,calendar_year', 'eligibility_rules' => 'nullable|array', 'is_active' => 'boolean']);
    }
}
