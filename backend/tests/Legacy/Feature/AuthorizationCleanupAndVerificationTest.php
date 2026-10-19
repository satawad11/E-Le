<?php

namespace Tests\Legacy\Feature;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelAssignment;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\LeavePolicyService;
use App\Services\OrgScopeResolver;
use App\Services\PermissionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationCleanupAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_identity_and_spatie_authorization_pipeline(): void
    {
        $role = Role::firstOrCreate(['name' => 'Department Head', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'leave.approval.dept', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasPermissionTo('leave.approval.dept'));
        $this->assertTrue($user->can('leave.approval.dept'));

        $evaluator = app(PermissionEvaluator::class);
        // เมธอดชื่อ allows() ไม่ใช่ can()
        $this->assertTrue($evaluator->allows($user, 'leave.approval.dept'));
    }

    public function test_explicit_scope_and_abac_evaluation_through_org_scope_resolver(): void
    {
        $unitA = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-A',
            'name' => 'ฝ่ายวิชาการ',
            'type' => 'academic',
            'effective_from' => '2025-01-01',
            'is_active' => true,
        ]);

        $unitB = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-B',
            'name' => 'ฝ่ายธุรการ',
            'type' => 'administrative',
            'effective_from' => '2025-01-01',
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'org_unit_id' => $unitA->id,
            'effective_from' => now()->toDateString(),
        ]);

        $resolver = app(OrgScopeResolver::class);

        // Under UNIT default scope, user can only see unitA, not unitB
        $this->assertTrue($resolver->canSeeOrgUnit($user, 'leave.view', $unitA->id, OrgScopeResolver::UNIT));
        $this->assertFalse($resolver->canSeeOrgUnit($user, 'leave.view', $unitB->id, OrgScopeResolver::UNIT));
    }

    public function test_approval_controller_authorization_without_legacy_role_id_column(): void
    {
        $role = Role::firstOrCreate(['name' => 'Approver Role', 'guard_name' => 'web']);
        $approver = User::factory()->create();
        $approver->assignRole($role);

        $step = ApprovalStep::create([
            'name' => 'ตรวจสอบขั้นที่ 1',
            'role_id' => $role->id,
            'step_order' => 1,
        ]);

        $requester = User::factory()->create();
        $leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $leave = Leave::create([
            'user_id' => $requester->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'leave_period' => 'full_day',
            'reason' => 'มีไข้สูง',
            'status' => 'Pending',
            'approval_level' => 1,
        ]);

        $this->giveQuota($requester, $leaveType, $leave);

        $res = $this->actingAs($approver)->postJson("/api/approvals/{$leave->id}/approve", [
            'comment' => 'อนุมัติเรียบร้อย',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('Approved', $leave->fresh()->status);
    }

    /**
     * ใบลาที่สร้างตรงผ่าน Model ไม่ได้ผ่าน LeaveService จึงไม่มี leave_balances
     * ตอนอนุมัติจะตกด้วย 422 "วันลาคงเหลือไม่เพียงพอ"
     * เทสต์ชุดนี้ตรวจเรื่อง authorization จึงต้องเปิดทางเรื่องโควตาให้ผ่านก่อน
     */
    private function giveQuota(User $user, LeaveType $type, Leave $leave): void
    {
        LeaveBalance::create([
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'year' => app(LeavePolicyService::class)->periodYearForLeave($leave),
            'quota' => 30,
            'used' => 0,
        ]);
    }

    public function test_delegation_approval_authorization(): void
    {
        $role = Role::firstOrCreate(['name' => 'Director Role', 'guard_name' => 'web']);
        $director = User::factory()->create();
        $director->assignRole($role);

        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $director->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
        ]);

        $step = ApprovalStep::create([
            'name' => 'อนุมัติโดย ผอ.',
            'role_id' => $role->id,
            'step_order' => 1,
        ]);

        $requester = User::factory()->create();
        $leaveType = LeaveType::create(['name' => 'ลากิจพิเศษ', 'max_days' => 15]);

        $leave = Leave::create([
            'user_id' => $requester->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'leave_period' => 'full_day',
            'reason' => 'ธุระส่วนตัว',
            'status' => 'Pending',
            'approval_level' => 1,
        ]);

        $this->giveQuota($requester, $leaveType, $leave);

        $res = $this->actingAs($delegate)->postJson("/api/approvals/{$leave->id}/approve", [
            'comment' => 'อนุมัติแทน ผอ.',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('Approved', $leave->fresh()->status);
    }
}
