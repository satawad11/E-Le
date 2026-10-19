<?php

namespace Tests\Legacy\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDelegation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEAVE-APPROVAL-02 — สายอนุมัติ งานบุคลากรตรวจสอบ → รองผู้อำนวยการ → ผู้อำนวยการ
 *
 * มติเจ้าของระบบ 2026-10-14: ทุกขั้นอนุมัติใบลาของตนเองได้ (ตั้งค่ารายขั้น)
 * แต่ต้องเป็นผู้อนุมัติของขั้นนั้นโดยตรงเท่านั้น — สิทธิ์ผู้ดูแลและการรับมอบอำนาจใช้ไม่ได้
 * และปิดช่อง "บันทึกวันลาแทนให้ตนเอง" ที่ข้ามสายอนุมัติทั้งสาย
 */
class LeaveSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $deputy;

    private User $director;

    private User $employee;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('leave.management.manage', 'web');
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = Department::create(['name' => 'ฝ่ายทดสอบ']);

        $roles = [];
        foreach (['งานบุคลากร', 'รองผู้อำนวยการ', 'ผู้อำนวยการ'] as $order => $name) {
            $roles[$order] = Role::create(['name' => $name, 'guard_name' => 'web']);
            ApprovalStep::create(['name' => "ขั้น {$name}", 'role_id' => $roles[$order]->id, 'step_order' => $order + 1, 'allow_self_approval' => true]);
        }

        $this->type = LeaveType::create(['name' => 'ลาทดสอบสายอนุมัติ', 'code_prefix' => 'S']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2020-01-01', 'period_basis' => 'fiscal_year', 'annual_quota' => 30,
            'exclude_holidays' => true, 'is_active' => true,
        ]);

        $make = fn () => User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->hr = $make();
        $this->hr->assignRole($roles[0]);
        $this->deputy = $make();
        $this->deputy->assignRole($roles[1]);
        $this->director = $make();
        $this->director->assignRole($roles[2]);
        $this->employee = $make();
    }

    private function submit(User $as): Leave
    {
        $start = now()->addWeeks(2)->next(Carbon::MONDAY);

        $id = $this->actingAs($as)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'reason' => 'ทดสอบสายอนุมัติ',
        ])->assertCreated()->json('leave.id');

        return Leave::findOrFail($id);
    }

    private function approve(User $as, Leave $leave)
    {
        return $this->actingAs($as)->postJson("/api/approvals/{$leave->id}/approve");
    }

    public function test_director_approves_their_own_leave_at_the_final_step_and_it_is_recorded(): void
    {
        $leave = $this->submit($this->director);

        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();
        $this->approve($this->director, $leave)->assertOk();

        $this->assertSame('Approved', $leave->fresh()->status);
        $final = $leave->approvals()->where('step_order', 3)->firstOrFail();
        $this->assertTrue($final->is_self_approval);
        $this->assertSame($this->director->id, $final->approver_id);
        $this->assertFalse($leave->approvals()->where('step_order', 2)->firstOrFail()->is_self_approval);

        $log = LeaveAuditLog::where('leave_id', $leave->id)->where('action', 'approval_approved')->latest('id')->firstOrFail();
        $this->assertTrue($log->after['self_approved']);
    }

    public function test_director_cannot_approve_their_own_leave_at_a_step_that_is_not_theirs(): void
    {
        $leave = $this->submit($this->director);

        $this->approve($this->director, $leave)->assertForbidden();

        $this->assertSame(1, (int) $leave->fresh()->approval_level);
    }

    public function test_deputy_approves_step_two_of_their_own_leave_but_the_director_still_decides(): void
    {
        $leave = $this->submit($this->deputy);

        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();
        $this->assertTrue($leave->approvals()->where('step_order', 2)->firstOrFail()->is_self_approval);

        $this->approve($this->deputy, $leave)->assertForbidden();
        $this->assertSame('Pending', $leave->fresh()->status);

        $this->approve($this->director, $leave)->assertOk();
        $this->assertSame('Approved', $leave->fresh()->status);
    }

    public function test_hr_officer_verifies_step_one_of_their_own_leave(): void
    {
        $leave = $this->submit($this->hr);

        $this->approve($this->hr, $leave)->assertOk();

        $this->assertSame(2, (int) $leave->fresh()->approval_level);
        $this->assertTrue($leave->approvals()->where('step_order', 1)->firstOrFail()->is_self_approval);
    }

    public function test_a_step_with_self_approval_switched_off_forbids_it(): void
    {
        ApprovalStep::where('step_order', 3)->update(['allow_self_approval' => false]);
        $leave = $this->submit($this->director);
        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();

        $this->approve($this->director, $leave)
            ->assertForbidden()
            ->assertJsonPath('message', 'ขั้นตอนนี้ไม่อนุญาตให้อนุมัติใบลาของตนเอง');
    }

    public function test_the_switch_is_pinned_to_the_leave_when_it_is_submitted(): void
    {
        $leave = $this->submit($this->director);
        // ปิดสวิตช์หลังยื่นแล้ว — ใบที่ยื่นไปแล้วใช้ค่าที่ pin ไว้
        ApprovalStep::where('step_order', 3)->update(['allow_self_approval' => false]);
        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();

        $this->approve($this->director, $leave)->assertOk();
    }

    public function test_leave_management_permission_does_not_let_a_requester_approve_steps_that_are_not_theirs(): void
    {
        $this->hr->givePermissionTo('leave.management.manage');
        $leave = $this->submit($this->hr);
        $this->approve($this->hr, $leave)->assertOk();

        $this->approve($this->hr, $leave)->assertForbidden();

        $this->assertSame(2, (int) $leave->fresh()->approval_level);
    }

    public function test_a_delegate_cannot_approve_the_delegators_own_leave_in_the_delegators_name(): void
    {
        UserDelegation::create([
            'user_id' => $this->director->id, 'delegate_id' => $this->employee->id,
            'start_date' => today()->subDay(), 'end_date' => today()->addMonth(), 'is_active' => true,
        ]);
        $leave = $this->submit($this->director);
        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();

        $this->approve($this->employee, $leave)->assertForbidden();

        $this->assertSame('Pending', $leave->fresh()->status);
    }

    public function test_a_requester_cannot_approve_their_own_leave_through_a_delegation_they_hold(): void
    {
        UserDelegation::create([
            'user_id' => $this->director->id, 'delegate_id' => $this->deputy->id,
            'start_date' => today()->subDay(), 'end_date' => today()->addMonth(), 'is_active' => true,
        ]);
        $leave = $this->submit($this->deputy);
        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();

        // ขั้นผู้อำนวยการ — รองฯ ได้รับมอบอำนาจจากผู้อำนวยการ แต่ใบนี้เป็นของรองฯ เอง
        $this->approve($this->deputy, $leave)->assertForbidden();
        $this->assertSame('Pending', $leave->fresh()->status);
    }

    public function test_a_delegate_still_approves_other_peoples_leave_for_the_delegator(): void
    {
        UserDelegation::create([
            'user_id' => $this->director->id, 'delegate_id' => $this->deputy->id,
            'start_date' => today()->subDay(), 'end_date' => today()->addMonth(), 'is_active' => true,
        ]);
        $leave = $this->submit($this->employee);
        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();

        $this->approve($this->deputy, $leave)->assertOk();

        $final = $leave->approvals()->where('step_order', 3)->firstOrFail();
        $this->assertSame($this->director->id, $final->delegated_from_user_id);
        $this->assertSame('Approved', $leave->fresh()->status);
    }

    public function test_own_leave_appears_in_the_pending_list_only_at_a_step_the_requester_may_approve(): void
    {
        $leave = $this->submit($this->director);
        $pendingIds = fn () => array_column($this->actingAs($this->director)->getJson('/api/approvals/pending')->assertOk()->json(), 'id');

        $this->assertNotContains($leave->id, $pendingIds());

        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();
        $this->assertContains($leave->id, $pendingIds());
    }

    public function test_rejecting_ones_own_leave_is_still_forbidden(): void
    {
        $leave = $this->submit($this->hr);

        $this->actingAs($this->hr)->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'ไม่ลาแล้ว'])->assertForbidden();
    }

    public function test_director_approves_the_cancellation_of_their_own_leave_at_their_step(): void
    {
        $leave = $this->submit($this->director);
        foreach ([$this->hr, $this->deputy, $this->director] as $approver) {
            $this->approve($approver, $leave)->assertOk();
        }
        $this->actingAs($this->director)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดราชการ'])->assertOk();

        $this->actingAs($this->director)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertForbidden();
        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();
        $this->actingAs($this->deputy)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();
        $this->actingAs($this->director)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();

        $this->assertSame('Cancelled', $leave->fresh()->status);
    }

    public function test_hr_cannot_record_an_already_approved_leave_for_themselves(): void
    {
        $this->hr->givePermissionTo('leave.management.manage');
        $payload = fn (User $for) => [
            'user_id' => $for->id, 'leave_type_id' => $this->type->id,
            'start_date' => today()->addWeeks(3)->next(Carbon::TUESDAY)->toDateString(), 'end_date' => today()->addWeeks(3)->next(Carbon::TUESDAY)->toDateString(),
            'reason' => 'บันทึกแทน',
            'signers' => [1 => $this->hr->id, 2 => $this->deputy->id, 3 => $this->director->id],
        ];

        $this->actingAs($this->hr)->postJson('/api/admin/leaves', $payload($this->hr))->assertForbidden();
        $this->assertDatabaseMissing('leaves', ['user_id' => $this->hr->id]);

        $this->actingAs($this->hr)->postJson('/api/admin/leaves', $payload($this->employee))->assertCreated();
    }
}
