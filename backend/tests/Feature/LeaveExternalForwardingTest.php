<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveExternalForwardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * REDESIGN-2569 · D-01 — "ให้ผู้อำนวยการอนุมัติก่อน แล้วค่อยนำส่งต่อ"
 */
class LeaveExternalForwardingTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $director;

    private User $hr;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $this->type = LeaveType::create(['name' => 'ลาทดสอบส่งต่อ', 'code_prefix' => 'ท']);
        $this->policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id, 'effective_from' => '2025-10-01',
            'annual_quota' => 30, 'period_basis' => 'fiscal_year', 'exclude_holidays' => true, 'allow_half_day' => true,
            'eligibility_rules' => ['requires_external_approval' => true], 'is_active' => true,
        ]);

        $dept = Department::create(['name' => 'งานทดสอบ']);
        $this->employee = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->director = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $role = Role::create(['name' => 'ผู้อนุมัติทดสอบ', 'guard_name' => 'web']);
        $this->director->assignRole($role);
        ApprovalStep::chain('leave')->delete();
        ApprovalStep::create(['name' => 'ผู้อำนวยการ', 'role_id' => $role->id, 'step_order' => 1, 'allow_self_approval' => true]);

        $this->hr = User::factory()->create();
        $this->hr->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'), Permission::findOrCreate('leave.approval.hr', 'web'));
    }

    private function submit(User $user, int $weeks = 1): array
    {
        $day = now()->addWeeks($weeks)->nextWeekday()->toDateString();

        return $this->actingAs($user)->postJson('/api/leaves', ['leave_type_id' => $this->type->id, 'start_date' => $day, 'end_date' => $day, 'reason' => 'ทดสอบส่งต่อ'])
            ->assertCreated()->json('leave');
    }

    private function approvedLeave(int $weeks = 1): Leave
    {
        $leave = $this->submit($this->employee, $weeks);
        $this->actingAs($this->director)->postJson("/api/approvals/{$leave['id']}/approve", ['comment' => 'อนุมัติ'])->assertOk();

        return Leave::findOrFail($leave['id']);
    }

    private function dispatchLeave(Leave $leave): void
    {
        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/external-forwarding/dispatch", [
            'authority' => 'เลขาธิการคณะกรรมการการอาชีวศึกษา', 'dispatch_number' => 'ศธ 0601/123', 'dispatch_date' => today()->toDateString(),
        ])->assertOk();
    }

    public function test_director_approves_first_then_the_leave_awaits_forwarding(): void
    {
        $leave = $this->approvedLeave();

        $this->assertSame('Approved', $leave->status, 'ผู้อำนวยการอนุมัติก่อน');
        $this->assertSame(LeaveExternalForwardingService::AWAITING_DISPATCH, LeaveExternalForwardingService::statusOf($leave));
        $this->assertStringContainsString('เกินอำนาจของสถานศึกษา', $leave->meta_data['external_forwarding']['reason']);
        $this->assertDatabaseHas('leave_audit_logs', ['leave_id' => $leave->id, 'action' => 'external_forwarding_required']);

        $ids = collect($this->actingAs($this->hr)->getJson('/api/admin/leave-governance/worklist?status=external_forwarding')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($leave->id), 'ต้องอยู่ในรายการรอส่งต่อของงานบุคลากร');
    }

    public function test_a_leave_within_college_authority_is_not_forwarded(): void
    {
        $this->policy->update(['eligibility_rules' => null]);

        $this->assertNull(LeaveExternalForwardingService::statusOf($this->approvedLeave()));
    }

    public function test_hr_records_dispatch_then_approval(): void
    {
        $leave = $this->approvedLeave();

        $this->dispatchLeave($leave);
        $this->assertSame(LeaveExternalForwardingService::AWAITING_RESULT, LeaveExternalForwardingService::statusOf($leave->fresh()));

        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/external-forwarding/result", [
            'result' => 'approved', 'result_number' => 'ศธ 0601/456', 'result_date' => today()->toDateString(),
        ])->assertOk();

        $leave->refresh();
        $this->assertSame(LeaveExternalForwardingService::APPROVED, LeaveExternalForwardingService::statusOf($leave));
        $this->assertSame('Approved', $leave->status);
    }

    public function test_rejection_by_the_authority_voids_the_leave_and_restores_the_balance(): void
    {
        $leave = $this->approvedLeave();
        $this->assertSame(1.0, (float) LeaveBalance::where('user_id', $this->employee->id)->value('used'));
        $this->dispatchLeave($leave);

        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/external-forwarding/result", [
            'result' => 'rejected', 'result_number' => 'ศธ 0601/789', 'result_date' => today()->toDateString(), 'note' => 'ไม่อนุญาต',
        ])->assertOk();

        $voided = Leave::withTrashed()->findOrFail($leave->id);
        $this->assertSame('Voided', $voided->status);
        $this->assertNotNull($voided->deleted_at);
        $this->assertSame(0.0, (float) LeaveBalance::where('user_id', $this->employee->id)->value('used'), 'ต้องคืนวันลา');
        $this->assertDatabaseHas('leave_audit_logs', ['leave_id' => $leave->id, 'action' => 'external_forwarding_rejected']);
    }

    public function test_result_cannot_be_recorded_before_dispatch_or_twice(): void
    {
        $leave = $this->approvedLeave();
        $result = ['result' => 'approved', 'result_number' => 'ศธ 1', 'result_date' => today()->toDateString()];

        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/external-forwarding/result", $result)->assertUnprocessable();

        $this->dispatchLeave($leave);
        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/external-forwarding/result", $result)->assertOk();
        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/external-forwarding/result", $result)->assertUnprocessable();
    }

    public function test_hr_with_leave_management_alone_can_open_the_worklist(): void
    {
        $leave = $this->approvedLeave();
        $hrHead = User::factory()->create();
        $hrHead->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        $ids = collect($this->actingAs($hrHead)->getJson('/api/admin/leave-governance/worklist?status=external_forwarding')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($leave->id), 'หัวหน้างานบุคลากรที่ถือเฉพาะ leave.management.manage ต้องเห็นรายการ');
    }

    public function test_only_hr_can_record_forwarding(): void
    {
        $leave = $this->approvedLeave();

        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/external-forwarding/dispatch", [
            'authority' => 'ใครก็ได้', 'dispatch_number' => '1', 'dispatch_date' => today()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($this->director)->getJson('/api/admin/leave-governance/worklist?status=external_forwarding')->assertForbidden();
    }

    public function test_the_directors_own_leave_is_always_forwarded(): void
    {
        $this->policy->update(['eligibility_rules' => null]);
        $root = OrgUnit::whereNull('parent_id')->first()
            ?? OrgUnit::create(['uuid' => (string) Str::uuid(), 'code' => 'ROOT-T', 'name' => 'วิทยาลัย', 'type' => 'root', 'effective_from' => '2025-01-01', 'is_active' => true]);
        DB::table('org_unit_managers')->insert(['uuid' => (string) Str::uuid(), 'org_unit_id' => $root->id, 'user_id' => $this->director->id,
            'assignment_type' => 'MANAGER', 'scope' => 'UNIT', 'effective_from' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()]);

        $leave = $this->submit($this->director);
        $this->actingAs($this->director)->postJson("/api/approvals/{$leave['id']}/approve", ['comment' => 'อนุมัติ'])->assertOk();

        $leave = Leave::findOrFail($leave['id']);
        $this->assertSame(LeaveExternalForwardingService::AWAITING_DISPATCH, LeaveExternalForwardingService::statusOf($leave));
        $this->assertStringContainsString('ใบลาของผู้อำนวยการ', $leave->meta_data['external_forwarding']['reason']);
    }

    public function test_sick_leave_over_the_yearly_limit_is_forwarded(): void
    {
        $this->policy->update(['eligibility_rules' => ['external_approval_after_days' => 1]]);

        $first = $this->approvedLeave();
        $this->assertNull(LeaveExternalForwardingService::statusOf($first), 'วันแรกยังไม่เกิน 1 วัน');

        $second = $this->approvedLeave(2);
        $this->assertSame(LeaveExternalForwardingService::AWAITING_DISPATCH, LeaveExternalForwardingService::statusOf($second));
        $this->assertStringContainsString('เกิน 1 วัน', $second->meta_data['external_forwarding']['reason']);
    }
}
