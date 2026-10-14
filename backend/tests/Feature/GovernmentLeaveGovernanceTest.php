<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GovernmentLeaveGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function setupPolicy(float $quota = 10): array
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบ', 'code_prefix' => 'T']);
        $policy = LeavePolicy::create(['personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id, 'effective_from' => '2025-10-01', 'annual_quota' => $quota, 'period_basis' => 'fiscal_year', 'exclude_holidays' => true, 'allow_half_day' => true, 'is_active' => true]);
        $dept = Department::create(['name' => 'ทดสอบ']);
        $user = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);

        return [$user, $type, $policy];
    }

    public function test_pending_request_reserves_balance_and_number_is_unique(): void
    {
        [$user,$type] = $this->setupPolicy();
        $start = now()->addWeek()->nextWeekday();
        $payload = ['leave_type_id' => $type->id, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(), 'reason' => 'ทดสอบ'];
        $a = $this->actingAs($user)->postJson('/api/leaves', $payload)->assertCreated()->json('leave');
        $start = now()->addWeeks(2)->nextWeekday();
        $payload['start_date'] = $start->toDateString();
        $payload['end_date'] = $start->copy()->addDay()->toDateString();
        $b = $this->actingAs($user)->postJson('/api/leaves', $payload)->assertCreated()->json('leave');
        $this->assertNotSame($a['document_number'], $b['document_number']);
        $this->assertGreaterThan(0, LeaveBalance::where('user_id', $user->id)->value('reserved'));
    }

    public function test_reject_releases_reserved_balance(): void
    {
        [$employee,$type] = $this->setupPolicy();
        $role = Role::create(['name' => 'Approver', 'guard_name' => 'web']);
        ApprovalStep::create(['name' => 'อนุมัติ', 'role_id' => $role->id, 'step_order' => 1]);
        $approver = User::factory()->create(['department_id' => $employee->department_id]);
        $approver->assignRole($role);
        $day = now()->addWeek()->nextWeekday()->toDateString();
        $leave = $this->actingAs($employee)->postJson('/api/leaves', ['leave_type_id' => $type->id, 'start_date' => $day, 'end_date' => $day, 'reason' => 'ทดสอบ'])->assertCreated()->json('leave');
        $this->actingAs($approver)->postJson("/api/approvals/{$leave['id']}/reject", ['comment' => 'ไม่อนุมัติ'])->assertOk();
        $this->assertEquals(0, (float) LeaveBalance::where('user_id', $employee->id)->value('reserved'));
    }

    public function test_updating_policy_quota_syncs_existing_balances(): void
    {
        [$user,$type,$policy] = $this->setupPolicy(10);
        $year = now()->month >= 10 ? now()->year + 1 : now()->year; // ปีงบประมาณปัจจุบัน (ยังไม่มี fiscal_years ในชุดทดสอบ)
        $balance = LeaveBalance::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year, 'quota' => 10, 'used' => 3, 'reserved' => 2, 'carried_forward' => 1, 'leave_policy_id' => $policy->id]);
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('leave.policy.manage', 'web'));
        $payload = ['personnel_type_id' => $policy->personnel_type_id, 'leave_type_id' => $policy->leave_type_id, 'effective_from' => '2025-10-01', 'annual_quota' => 20, 'period_basis' => 'fiscal_year', 'is_active' => true];
        $res = $this->actingAs($admin)->putJson("/api/admin/leave-policies/{$policy->id}", $payload)->assertOk();
        $this->assertSame(1, $res->json('sync.synced'));
        $this->assertSame(0, $res->json('sync.over_quota'));
        $balance->refresh();
        $this->assertEquals(20.0, (float) $balance->quota);
        $this->assertEquals(3.0, (float) $balance->used);
        $this->assertEquals(2.0, (float) $balance->reserved);
        $this->assertEquals(1.0, (float) $balance->carried_forward);
    }

    public function test_leave_policy_changes_are_audited_with_before_and_after(): void
    {
        [, $type, $policy] = $this->setupPolicy();
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('leave.policy.manage', 'web'));
        $payload = ['personnel_type_id' => $policy->personnel_type_id, 'leave_type_id' => $type->id, 'effective_from' => '2025-10-01', 'annual_quota' => 15, 'period_basis' => 'fiscal_year', 'is_active' => true];

        $this->actingAs($admin)->putJson("/api/admin/leave-policies/{$policy->id}", $payload)->assertOk();
        $createdId = $this->actingAs($admin)->postJson('/api/admin/leave-policies', ['effective_from' => '2027-10-01'] + $payload)->assertCreated()->json('policy.id');

        $updated = AuditLog::where('action', 'leave_policy.updated')->where('resource_id', (string) $policy->id)->firstOrFail();
        $this->assertSame($admin->id, $updated->actor_id);
        $this->assertEquals(10, $updated->before['annual_quota']);
        $this->assertEquals(15, $updated->after['annual_quota']);
        $this->assertTrue(AuditLog::where('action', 'leave_policy.created')->where('resource_id', (string) $createdId)->exists());
    }

    public function test_evidence_storage_is_not_public(): void
    {
        [$user,$type] = $this->setupPolicy();
        $this->assertFalse(file_exists(public_path('leave-evidences')));
    }

    public function test_hr_can_void_leave_without_losing_history(): void
    {
        [$employee,$type] = $this->setupPolicy();
        $day = now()->addWeek()->nextWeekday()->toDateString();
        $leave = $this->actingAs($employee)->postJson('/api/leaves', ['leave_type_id' => $type->id, 'start_date' => $day, 'end_date' => $day, 'reason' => 'ทดสอบยกเลิกเอกสาร'])->assertCreated()->json('leave');
        $hr = User::factory()->create();
        $hr->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        $this->actingAs($hr)->postJson("/api/leaves/{$leave['id']}/void", ['reason' => 'ข้อมูลเอกสารไม่ถูกต้อง'])->assertOk();

        $voided = Leave::withTrashed()->findOrFail($leave['id']);
        $this->assertSame('Voided', $voided->status);
        $this->assertNotNull($voided->deleted_at);
        $this->assertDatabaseHas('leave_audit_logs', ['leave_id' => $leave['id'], 'action' => 'voided']);
    }
}
