<?php

namespace Tests\Legacy\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ApprovalWorkflowManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Role $adminRole;

    private Role $employeeRole;

    private Role $hrRole;

    private Role $deputyRole;

    private Role $directorRole;

    private PersonnelType $personnelType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $this->employeeRole = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $this->hrRole = Role::firstOrCreate(['name' => 'HR Head', 'guard_name' => 'web']);
        $this->deputyRole = Role::firstOrCreate(['name' => 'Deputy Director', 'guard_name' => 'web']);
        $this->directorRole = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);

        $this->adminRole->givePermissionTo(
            Permission::firstOrCreate(['name' => 'manage_workflow_steps', 'guard_name' => 'web'])
        );
        $this->adminRole->givePermissionTo(
            Permission::firstOrCreate(['name' => 'leave.management.manage', 'guard_name' => 'web'])
        );

        $dept = Department::firstOrCreate(['name' => 'วิชาการ'], ['code' => 'ACAD', 'is_active' => true]);
        $this->personnelType = PersonnelType::firstOrCreate(
            ['code' => 'civil_servant'],
            ['name' => 'ข้าราชการพลเรือน', 'is_active' => true]
        );

        FiscalYear::firstOrCreate(
            ['year' => (int) date('Y')],
            ['name' => 'ปีงบประมาณ '.((int) date('Y') + 543), 'start_date' => date('Y').'-01-01', 'end_date' => date('Y').'-12-31', 'is_active' => true]
        );

        $this->admin = User::factory()->create([
            'department_id' => $dept->id,
            'personnel_type_id' => $this->personnelType->id,
        ]);
        $this->admin->assignRole($this->adminRole);

        $this->employee = User::factory()->create([
            'department_id' => $dept->id,
            'personnel_type_id' => $this->personnelType->id,
        ]);
        $this->employee->assignRole($this->employeeRole);
    }

    private function createPolicyAndBalance(User $user, float $quota = 10): array
    {
        $type = LeaveType::create(['name' => 'ลาพักผ่อน_'.uniqid(), 'code_prefix' => 'V', 'is_active' => true]);
        $policy = LeavePolicy::create([
            'personnel_type_id' => $this->personnelType->id,
            'leave_type_id' => $type->id,
            'effective_from' => '2020-01-01',
            'annual_quota' => $quota,
            'period_basis' => 'fiscal_year',
            'exclude_holidays' => false,
            'allow_half_day' => true,
            'allow_past_dates' => true,
            'is_active' => true,
        ]);

        LeaveBalance::create([
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'leave_policy_id' => $policy->id,
            'year' => (int) date('Y'),
            'quota' => $quota,
            'used' => 0,
            'reserved' => 0,
            'carried_forward' => 0,
        ]);

        return [$type, $policy];
    }

    /**
     * TEST-01: โหลด workflow
     */
    public function test_01_load_workflow_steps(): void
    {
        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hrRole->id, 'name' => 'ตรวจสอบโดย HR']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->directorRole->id, 'name' => 'อนุมัติโดย ผอ.']);

        $res = $this->actingAs($this->admin)->getJson('/api/admin/workflow-steps');

        $res->assertOk();
        $res->assertHeader('X-Workflow-Fingerprint');
        $this->assertCount(2, $res->json());
        $this->assertEquals('ตรวจสอบโดย HR', $res->json()[0]['name']);
        $this->assertEquals(1, $res->json()[0]['step_order']);
    }

    /**
     * TEST-02, TEST-03, TEST-04, TEST-05: เพิ่ม ลบ เปลี่ยนลำดับ และบันทึก
     */
    public function test_02_to_05_save_and_reorder_workflow(): void
    {
        $payload = [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => 'ขั้นที่ 1 HR', 'step_order' => 1],
                ['role_id' => $this->deputyRole->id, 'name' => 'ขั้นที่ 2 รอง ผอ.', 'step_order' => 2],
                ['role_id' => $this->directorRole->id, 'name' => 'ขั้นที่ 3 ผอ.', 'step_order' => 3],
            ],
        ];

        $res = $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', $payload);

        $res->assertOk();
        $res->assertJsonPath('message', 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว');
        $this->assertDatabaseCount('approval_steps', 3);
        $this->assertDatabaseHas('approval_steps', ['step_order' => 1, 'role_id' => $this->hrRole->id]);
        $this->assertDatabaseHas('approval_steps', ['step_order' => 2, 'role_id' => $this->deputyRole->id]);
        $this->assertDatabaseHas('approval_steps', ['step_order' => 3, 'role_id' => $this->directorRole->id]);

        // Reorder & Remove step 2 (Deputy)
        $reorderedPayload = [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => 'ขั้นที่ 1 HR', 'step_order' => 1],
                ['role_id' => $this->directorRole->id, 'name' => 'ขั้นที่ 2 ผอ.', 'step_order' => 2],
            ],
        ];

        $res2 = $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', $reorderedPayload);
        $res2->assertOk();
        $this->assertDatabaseCount('approval_steps', 2);
        $this->assertDatabaseHas('approval_steps', ['step_order' => 2, 'role_id' => $this->directorRole->id]);
    }

    /**
     * LEAVE-APPROVAL-02: สวิตช์ "อนุมัติใบลาของตนเองได้" บันทึกรายขั้น และไม่ส่งมาถือว่าปิด
     */
    public function test_self_approval_switch_is_saved_per_step(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => 'ขั้นที่ 1 HR', 'step_order' => 1],
                ['role_id' => $this->directorRole->id, 'name' => 'ขั้นที่ 2 ผอ.', 'step_order' => 2, 'allow_self_approval' => true],
            ],
        ])->assertOk()->assertJsonPath('steps.1.allow_self_approval', true);

        $this->assertDatabaseHas('approval_steps', ['step_order' => 1, 'allow_self_approval' => false]);
        $this->assertDatabaseHas('approval_steps', ['step_order' => 2, 'allow_self_approval' => true]);
    }

    /**
     * TEST-06: refresh แล้วยังคงข้อมูล
     */
    public function test_06_refresh_retains_data(): void
    {
        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hrRole->id, 'name' => 'HR']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->directorRole->id, 'name' => 'Director']);

        $res = $this->actingAs($this->admin)->getJson('/api/admin/workflow-steps');
        $res->assertOk();
        $this->assertEquals(2, count($res->json()));
    }

    /**
     * TEST-07: Validation sequence & constraints
     */
    public function test_07_validation_constraints(): void
    {
        // Empty array forbidden
        $resEmpty = $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', ['steps' => []]);
        $resEmpty->assertStatus(422);

        // Blank name forbidden
        $resBlankName = $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => '   ', 'step_order' => 1],
            ],
        ]);
        $resBlankName->assertStatus(422);

        // Invalid role_id forbidden
        $resInvalidRole = $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['role_id' => 99999, 'name' => 'Unknown', 'step_order' => 1],
            ],
        ]);
        $resInvalidRole->assertStatus(422);
    }

    /**
     * TEST-08: Permission security check
     */
    public function test_08_permission_security(): void
    {
        // Employee without manage_workflow_steps permission gets 403
        $res = $this->actingAs($this->employee)->getJson('/api/admin/workflow-steps');
        $res->assertForbidden();

        $resPost = $this->actingAs($this->employee)->postJson('/api/admin/workflow-steps', [
            'steps' => [['role_id' => $this->hrRole->id, 'name' => 'HR', 'step_order' => 1]],
        ]);
        $resPost->assertForbidden();
    }

    /**
     * TEST-09, TEST-10: สร้าง Leave Request ใหม่ และตรวจ Approval Steps ที่ถูกสร้าง
     */
    public function test_09_and_10_new_leave_generates_approval_steps(): void
    {
        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hrRole->id, 'name' => 'HR ตรวจสอบ']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->deputyRole->id, 'name' => 'รอง ผอ. พิจารณา']);
        ApprovalStep::create(['step_order' => 3, 'role_id' => $this->directorRole->id, 'name' => 'ผอ. อนุมัติ']);

        [$leaveType, $policy] = $this->createPolicyAndBalance($this->employee, 10);

        $leaveService = app(LeaveService::class);
        $leave = $leaveService->createLeaveRequest([
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'leave_period' => 'full_day',
            'reason' => 'ไปทำธุระส่วนตัว',
        ], $this->employee->id, null);

        $this->assertDatabaseCount('leave_approvals', 3);
        $approvals = LeaveApproval::where('leave_id', $leave->id)->orderBy('step_order')->get();
        $this->assertEquals(1, $approvals[0]->step_order);
        $this->assertEquals('HR ตรวจสอบ', $approvals[0]->step_name);
        $this->assertEquals($this->hrRole->id, $approvals[0]->required_role_id);
        $this->assertEquals('Pending', $approvals[0]->status);

        $this->assertEquals(2, $approvals[1]->step_order);
        $this->assertEquals($this->deputyRole->id, $approvals[1]->required_role_id);

        $this->assertEquals(3, $approvals[2]->step_order);
        $this->assertEquals($this->directorRole->id, $approvals[2]->required_role_id);
    }

    /**
     * TEST-11, TEST-12: approval step-by-step และ reject
     */
    public function test_11_and_12_step_by_step_approval_and_rejection(): void
    {
        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hrRole->id, 'name' => 'HR']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->directorRole->id, 'name' => 'Director']);

        [$leaveType, $policy] = $this->createPolicyAndBalance($this->employee, 15);

        $leave = app(LeaveService::class)->createLeaveRequest([
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'leave_period' => 'full_day',
            'reason' => 'ลากิจ',
        ], $this->employee->id, null);

        $hrUser = User::factory()->create([
            'personnel_type_id' => $this->personnelType->id,
        ]);
        $hrUser->assignRole($this->hrRole);
        UserProfile::create(['user_id' => $hrUser->id, 'position' => 'หัวหน้างานบุคคล']);

        // Step 1: HR Approve
        $resApprove = $this->actingAs($hrUser)->postJson("/api/approvals/{$leave->id}/approve", [
            'comment' => 'เอกสารครบถ้วน',
        ]);
        $resApprove->assertOk();

        $leave->refresh();
        $this->assertEquals(2, $leave->approval_level);
        $this->assertEquals('Pending', $leave->status);

        // Step 2: Director Reject
        $directorUser = User::factory()->create([
            'personnel_type_id' => $this->personnelType->id,
        ]);
        $directorUser->assignRole($this->directorRole);
        UserProfile::create(['user_id' => $directorUser->id, 'position' => 'ผู้อำนวยการ']);

        $resReject = $this->actingAs($directorUser)->postJson("/api/approvals/{$leave->id}/reject", [
            'comment' => 'ไม่อนุมัติเนื่องจากติดราชการด่วน',
        ]);
        $resReject->assertOk();

        $leave->refresh();
        $this->assertEquals('Rejected', $leave->status);
    }

    /**
     * TEST-13: Request เก่าไม่ถูก workflow ใหม่กระทบ (Snapshot Integrity)
     */
    public function test_13_old_request_not_affected_by_new_workflow(): void
    {
        // 1. Setup initial 2-step workflow
        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hrRole->id, 'name' => 'HR v1']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->directorRole->id, 'name' => 'Director v1']);

        [$leaveType, $policy] = $this->createPolicyAndBalance($this->employee, 30);

        // Create leave under v1
        $leave = app(LeaveService::class)->createLeaveRequest([
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(7)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
            'leave_period' => 'full_day',
            'reason' => 'ลาป่วย',
        ], $this->employee->id, null);

        $this->assertEquals(2, $leave->approvals()->count());
        $this->assertEquals('HR v1', $leave->approvals()->where('step_order', 1)->value('step_name'));

        // 2. Admin updates system workflow to 4 steps
        $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => 'HR v2', 'step_order' => 1],
                ['role_id' => $this->deputyRole->id, 'name' => 'Deputy v2', 'step_order' => 2],
                ['role_id' => $this->deputyRole->id, 'name' => 'Deputy2 v2', 'step_order' => 3],
                ['role_id' => $this->directorRole->id, 'name' => 'Director v2', 'step_order' => 4],
            ],
        ])->assertOk();

        // 3. Old leave still preserves its 2-step snapshot and names
        $leave->refresh();
        $this->assertEquals(2, $leave->approvals()->count());
        $this->assertEquals('HR v1', $leave->approvals()->where('step_order', 1)->value('step_name'));
        $this->assertEquals('Director v1', $leave->approvals()->where('step_order', 2)->value('step_name'));
    }

    /**
     * TEST-14: Concurrent update protection (Optimistic Concurrency)
     */
    public function test_14_optimistic_concurrency_conflict(): void
    {
        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hrRole->id, 'name' => 'Step 1']);

        // Admin 1 reads workflow
        $res = $this->actingAs($this->admin)->getJson('/api/admin/workflow-steps');
        $oldFingerprint = $res->headers->get('X-Workflow-Fingerprint');

        // Admin 2 modifies workflow first
        $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => 'Step 1 Modified by Admin 2', 'step_order' => 1],
            ],
        ])->assertOk();

        // Admin 1 attempts to save with outdated fingerprint
        $conflictRes = $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['role_id' => $this->hrRole->id, 'name' => 'Step 1 from Admin 1', 'step_order' => 1],
            ],
            'fingerprint' => $oldFingerprint,
        ]);

        $conflictRes->assertStatus(409);
        $conflictRes->assertJsonPath('conflict', true);
    }
}
