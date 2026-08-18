<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelAssignment;
use App\Models\User;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalResolverAndWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_steps_configuration_with_resolvers_and_fingerprint(): void
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin->assignRole($role);

        $specificUser = User::factory()->create();

        // 1. Save workflow steps with different resolver types
        $res = $this->actingAs($admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                [
                    'name' => 'หัวหน้าแผนกตรวจสอบ',
                    'approver_type' => 'DEPARTMENT_HEAD',
                    'role_id' => null,
                ],
                [
                    'name' => 'รองผู้อำนวยการกลั่นกรอง',
                    'approver_type' => 'DIVISION_DIRECTOR',
                    'role_id' => null,
                ],
                [
                    'name' => 'ผู้เชี่ยวชาญเฉพาะ',
                    'approver_type' => 'SPECIFIC_USER',
                    'specific_user_id' => $specificUser->id,
                ],
            ],
        ]);

        $res->assertStatus(200);
        $fingerprint = $res->json('fingerprint');
        $this->assertNotEmpty($fingerprint);
        $this->assertCount(3, ApprovalStep::all());

        // 2. Test Concurrency conflict (409) with stale fingerprint
        $staleRes = $this->actingAs($admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['name' => 'ขั้นตอนเดียว', 'approver_type' => 'DIRECTOR'],
            ],
            'fingerprint' => 'stale_fingerprint_hash_12345',
        ]);

        $staleRes->assertStatus(409);
        $staleRes->assertJsonPath('conflict', true);
    }

    public function test_dynamic_department_head_resolver_via_org_unit_managers(): void
    {
        $unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ACAD-01',
            'name' => 'แผนกวิชาคอมพิวเตอร์',
            'type' => 'academic',
            'effective_from' => '2025-01-01',
            'is_active' => true,
        ]);

        $deptHead = User::factory()->create();
        $requester = User::factory()->create();

        // Assign requester to unit
        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $requester->id,
            'org_unit_id' => $unit->id,
            'effective_from' => '2025-01-01',
        ]);

        // Assign deptHead as manager of this unit in org_unit_managers
        // คอลัมน์จริงคือ uuid / assignment_type / note
        // เดิมเทสต์เขียน position_title กับ management_type ซึ่งไม่มีอยู่ในสคีมาเลย
        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $unit->id,
            'user_id' => $deptHead->id,
            'assignment_type' => 'MANAGER',
            'scope' => 'UNIT',
            'note' => 'หัวหน้าแผนกวิชาคอมพิวเตอร์',
            'effective_from' => '2025-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $step = [
            'approver_type' => 'DEPARTMENT_HEAD',
            'role_id' => null,
            'name' => 'อนุมัติโดยหัวหน้าแผนก',
        ];

        $resolver = app(ApproverResolverService::class);
        $eligible = $resolver->resolveEligibleApproverIds($requester, $step);

        $this->assertContains($deptHead->id, $eligible);
        $this->assertTrue($resolver->isAuthorizedApprover($deptHead, $requester, $step));
        $this->assertFalse($resolver->isAuthorizedApprover($requester, $requester, $step)); // self-approval forbidden
    }

    public function test_leave_submission_snapshot_and_workflow_immutability(): void
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin->assignRole($role);

        $approverUser = User::factory()->create();

        // Setup 2 steps workflow
        ApprovalStep::create([
            'step_order' => 1,
            'name' => 'ขั้นแรกก่อนแก้',
            'approver_type' => 'SPECIFIC_USER',
            'specific_user_id' => $approverUser->id,
        ]);
        ApprovalStep::create([
            'step_order' => 2,
            'name' => 'ขั้นที่สองก่อนแก้',
            'approver_type' => 'ROLE',
            'role_id' => $role->id,
        ]);

        $requester = User::factory()->create();
        $leaveType = LeaveType::create(['name' => 'ลาป่วยทดสอบ', 'max_days' => 30]);

        $leave = Leave::create([
            'user_id' => $requester->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ snapshot สายอนุมัติ',   // leaves.reason เป็น NOT NULL
            'status' => 'Pending',
            'approval_level' => 1,
        ]);

        LeaveApproval::create([
            'leave_id' => $leave->id,
            'step_order' => 1,
            'step_name' => 'ขั้นแรกก่อนแก้',
            'required_role_id' => $role->id,
            'status' => 'Pending',
        ]);
        LeaveApproval::create([
            'leave_id' => $leave->id,
            'step_order' => 2,
            'step_name' => 'ขั้นที่สองก่อนแก้',
            'required_role_id' => $role->id,
            'status' => 'Pending',
        ]);

        // Now modify global workflow configuration completely (3 new steps)
        $this->actingAs($admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['name' => 'สเต็ปใหม่ 1', 'approver_type' => 'DIRECTOR'],
                ['name' => 'สเต็ปใหม่ 2', 'approver_type' => 'HR_OFFICER'],
                ['name' => 'สเต็ปใหม่ 3', 'approver_type' => 'ROLE', 'role_id' => $role->id],
            ],
        ]);

        // Existing leave snapshot must be preserved (still 2 steps)
        $leaveApprovals = LeaveApproval::where('leave_id', $leave->id)->orderBy('step_order')->get();
        $this->assertCount(2, $leaveApprovals);
        $this->assertEquals('ขั้นแรกก่อนแก้', $leaveApprovals[0]->step_name);
        $this->assertEquals('ขั้นที่สองก่อนแก้', $leaveApprovals[1]->step_name);
    }
}
