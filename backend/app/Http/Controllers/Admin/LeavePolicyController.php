<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\PersonnelType;
use App\Services\AuditLogger;
use App\Services\LeavePolicyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeavePolicyController extends Controller
{
    public function __construct(
        private LeavePolicyService $policies,
        private AuditLogger $audit,
    ) {}

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

        $this->audit->log('leave_policy_created', 'LeavePolicy', $policy->id, after: $policy->toArray());

        return response()->json(['policy' => $policy->load(['personnelType', 'leaveType']), 'sync' => $this->policies->syncBalances($policy)], 201);
    }

    /**
     * แก้นโยบายสิทธิการลา
     *
     * LS-01 — นโยบายที่ใบลาหรือยอดวันลาอ้างถึงแล้วห้ามแก้ทับในแถวเดิม
     *
     * เดิมใช้ `$policy->update()` เสมอ การแก้เพดานวันลาหรือรอบการนับเพียงครั้งเดียวจึงย้อน
     * ไปเปลี่ยน "หลักเกณฑ์ที่ใบลาเก่าถูกอนุมัติภายใต้" ซึ่งตรวจสอบย้อนหลังไม่ได้อีกเลย
     * ทั้งที่ `PROJECT_RULES` กำหนดว่า Policy ต้อง versioned + effective dated
     *
     * พฤติกรรมใหม่:
     *   - ยังไม่มีใครอ้างถึง → แก้ในแถวเดิมได้ตามปกติ
     *   - มีผู้อ้างถึงแล้ว และระบุวันมีผลใหม่ที่หลังกว่าเดิม → ปิดฉบับเดิมด้วย `effective_to`
     *     แล้วออกฉบับใหม่ต่อจากนั้น (ฉบับเดิมยังอยู่ครบ ใบลาเก่าจึงอ่านหลักเกณฑ์เดิมได้เหมือนเดิม)
     *   - มีผู้อ้างถึงแล้ว แต่วันมีผลใหม่ไม่หลังกว่าเดิม → ปฏิเสธพร้อมบอกวิธีแก้
     */
    public function update(Request $r, LeavePolicy $policy)
    {
        $data = $this->validated($r);
        $references = $this->referenceCount($policy);

        if ($references === 0) {
            $before = $policy->toArray();
            $policy->update($data);

            $this->audit->log('leave_policy_updated', 'LeavePolicy', $policy->id, before: $before, after: $policy->fresh()->toArray());

            return response()->json(['policy' => $policy->fresh(['personnelType', 'leaveType']), 'sync' => $this->policies->syncBalances($policy)]);
        }

        $newFrom = Carbon::parse($data['effective_from']);

        if ($newFrom->lte(Carbon::parse($policy->effective_from))) {
            return response()->json([
                'message' => "นโยบายนี้ถูกใช้กับใบลา/ยอดวันลา {$references} รายการแล้ว จึงแก้ทับของเดิมไม่ได้ — "
                    .'กรุณาระบุ "มีผลตั้งแต่" เป็นวันที่หลังจาก '.Carbon::parse($policy->effective_from)->toDateString()
                    .' ระบบจะออกนโยบายฉบับใหม่ให้และเก็บฉบับเดิมไว้เป็นประวัติ',
                'errors' => ['effective_from' => ['ต้องเป็นวันที่หลังวันมีผลของฉบับเดิม']],
            ], 422);
        }

        $created = DB::transaction(function () use ($policy, $data, $newFrom) {
            $before = $policy->toArray();

            // ปิดฉบับเดิมไว้หนึ่งวันก่อนฉบับใหม่มีผล — ไม่แตะค่าหลักเกณฑ์ใด ๆ ของฉบับเดิม
            $policy->update(['effective_to' => $newFrom->copy()->subDay()->toDateString()]);

            $new = LeavePolicy::create($data);

            $this->audit->log('leave_policy_versioned', 'LeavePolicy', $new->id, before: $before, after: $new->toArray());

            return $new;
        }, 5);

        return response()->json([
            'message' => 'ออกนโยบายฉบับใหม่เรียบร้อยแล้ว — ฉบับเดิมถูกเก็บไว้เป็นประวัติและยังใช้กับใบลาที่อ้างถึงอยู่',
            'policy' => $created->load(['personnelType', 'leaveType']),
            'previous_policy_id' => $policy->id,
            'sync' => $this->policies->syncBalances($created),
        ], 201);
    }

    /**
     * จำนวน "เอกสาร" ที่อ้างถึงนโยบายฉบับนี้
     *
     * นับเฉพาะใบลา เพราะใบลาคือหลักฐานว่า "อนุมัติภายใต้หลักเกณฑ์ฉบับใด" ซึ่งต้องคงที่ตลอดไป
     * ส่วน `leave_balances` เป็นยอดปัจจุบันที่ระบบซิงก์ตามนโยบายอยู่แล้วโดยการออกแบบ
     * การแก้เพดานวันลาแล้วให้ยอดปัจจุบันตามไปด้วยจึงเป็นพฤติกรรมที่ถูกต้อง ไม่ใช่การเขียนทับประวัติ
     */
    private function referenceCount(LeavePolicy $policy): int
    {
        return Leave::where('leave_policy_id', $policy->id)->count();
    }

    private function validated(Request $r): array
    {
        return $r->validate(['personnel_type_id' => 'required|exists:personnel_types,id', 'leave_type_id' => 'required|exists:leave_types,id', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'annual_quota' => 'nullable|numeric|min:0', 'max_days_per_request' => 'nullable|numeric|min:0', 'max_days_with_pay' => 'nullable|numeric|min:0', 'minimum_service_days' => 'integer|min:0', 'notice_period_days' => 'integer|min:0', 'allow_past_dates' => 'boolean', 'exclude_holidays' => 'boolean', 'allow_half_day' => 'boolean', 'requires_substitute' => 'boolean', 'attachment_required_after_days' => 'nullable|numeric|min:0', 'required_evidence_type' => 'nullable|string|max:80', 'is_carry_forward' => 'boolean', 'max_carry_forward' => 'numeric|min:0', 'max_accumulated' => 'nullable|numeric|min:0', 'period_basis' => 'required|in:fiscal_year,calendar_year', 'eligibility_rules' => 'nullable|array', 'is_active' => 'boolean']);
    }
}
