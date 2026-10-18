<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\PersonnelType;
use App\Services\AuditLogger;
use App\Services\LeavePolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        return response()->json(['policy' => $policy->load(['personnelType', 'leaveType']), 'sync' => $this->policies->syncBalances($policy)], 201);
    }

    /**
     * แก้นโยบายที่มีใบลาอ้างถึงแล้ว = ปิดรุ่นเดิมและสร้างรุ่นใหม่ (PROJECT_RULES ข้อ 7 ห้ามเขียนทับประวัติ effective-dated)
     *
     * ใบลาที่ยื่นไปแล้วยังผูกกับรุ่นเดิม รุ่นใหม่มีผลตั้งแต่วันที่ระบุ (ไม่ก่อนวันนี้และไม่ก่อนวันเริ่มของรุ่นเดิม)
     * นโยบายที่ยังไม่มีใบลาอ้างถึง หรือการแก้เฉพาะวันสิ้นสุด/สถานะเปิดใช้ (การปิดรุ่น) แก้ในแถวเดิมได้
     */
    public function update(Request $r, LeavePolicy $policy)
    {
        $data = $this->validated($r);
        $before = $policy->toArray();

        $substantive = collect($data)->except(['effective_to', 'is_active'])
            ->contains(fn ($value, $key) => $this->differs($policy, $key, $value));
        $inUse = Leave::withTrashed()->where('leave_policy_id', $policy->id)->exists();

        if (! $inUse || ! $substantive) {
            $policy->update($data);
            $this->audit->log('leave_policy.updated', $policy, null, $before, $policy->fresh()->toArray());

            return response()->json(['policy' => $policy->fresh(['personnelType', 'leaveType']), 'sync' => $this->policies->syncBalances($policy), 'versioned' => false]);
        }

        $from = collect([Carbon::parse($data['effective_from'])->startOfDay(), today(), $policy->effective_from->copy()->addDay()])->max();

        $next = DB::transaction(function () use ($policy, $data, $from, $before) {
            $policy->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            $next = LeavePolicy::create(array_merge($data, ['effective_from' => $from->toDateString()]));
            $this->audit->log('leave_policy.versioned', $next, null, $before, $next->toArray(), "ปิดรุ่น #{$policy->id} เพราะมีใบลาอ้างถึงแล้ว");

            return $next;
        });

        return response()->json([
            'policy' => $next->load(['personnelType', 'leaveType']),
            'sync' => $this->policies->syncBalances($next),
            'versioned' => true,
            'message' => 'นโยบายนี้มีใบลาอ้างถึงแล้ว ระบบจึงปิดรุ่นเดิมและสร้างรุ่นใหม่มีผลตั้งแต่ '.$from->format('j/n/').($from->year + 543).' ใบลาที่ยื่นไปแล้วยังใช้ค่าเดิม',
        ]);
    }

    private function differs(LeavePolicy $policy, string $key, mixed $value): bool
    {
        $current = $policy->getAttribute($key);
        if ($current instanceof \DateTimeInterface) {
            return $value === null || Carbon::parse($value)->toDateString() !== $current->format('Y-m-d');
        }
        if (is_array($current) || is_array($value)) {
            return json_encode($current ?: null) !== json_encode($value ?: null);
        }
        if (is_numeric($current) || is_numeric($value)) {
            return (float) $current !== (float) $value || ($current === null) !== ($value === null);
        }

        return (string) $current !== (string) $value;
    }

    private function validated(Request $r): array
    {
        return $r->validate(['personnel_type_id' => 'required|exists:personnel_types,id', 'leave_type_id' => 'required|exists:leave_types,id', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'annual_quota' => 'nullable|numeric|min:0', 'max_days_per_request' => 'nullable|numeric|min:0', 'max_days_with_pay' => 'nullable|numeric|min:0', 'minimum_service_days' => 'integer|min:0', 'notice_period_days' => 'integer|min:0', 'allow_past_dates' => 'boolean', 'exclude_holidays' => 'boolean', 'allow_half_day' => 'boolean', 'requires_substitute' => 'boolean', 'attachment_required_after_days' => 'nullable|numeric|min:0', 'required_evidence_type' => 'nullable|string|max:80', 'is_carry_forward' => 'boolean', 'max_carry_forward' => 'numeric|min:0', 'max_accumulated' => 'nullable|numeric|min:0', 'period_basis' => 'required|in:fiscal_year,calendar_year', 'eligibility_rules' => 'nullable|array', 'is_active' => 'boolean']);
    }
}
