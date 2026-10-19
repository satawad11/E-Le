<?php

namespace Tests\Legacy\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\OrgUnitManager;
use App\Models\PersonnelType;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\AccessReviewService;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ACCESS-GOV-01 — การบริหารการเข้าถึงตามระเบียบบริหารสถานศึกษา พ.ศ. 2569 (docs/ACCESS-GOVERNANCE-2569.md)
 *
 *  1. ขั้นอนุมัติแบบหัวหน้าของหน่วยงานที่ระบุ (Q3: รองฯ ฝ่ายบริหารทรัพยากรอนุมัติทุกฝ่าย)
 *  2. ปิดทางถอยตามชื่อบทบาทได้ (รองฯ ทุกคนอนุมัติทุกฝ่าย)
 *  3. ยกเลิกวันลาหลังอนุมัติผ่านทุกขั้นตอนเดิมของใบลานั้น (Q4)
 *  4. โครงสร้างหน่วยงานและทะเบียนหัวหน้าหน่วยงานอ้างคำสั่ง ไม่มีการลบ
 *  5. บุคลากรพ้นสภาพ: ระงับบัญชีและปิดอำนาจทุกช่องทาง
 *  6. ทบทวนสิทธิ์รายปีการศึกษา (Q9)
 */
class AccessGovernance2569Test extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = OrgUnit::where('code', 'ORG-ROOT')->firstOrFail();
        $this->superAdmin = User::factory()->create(['is_active' => true]);
        $this->superAdmin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    }

    private function unit(string $name, ?OrgUnit $parent = null, string $type = 'section'): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'parent_id' => ($parent ?? $this->root)->id,
            'code' => 'T-'.Str::upper(Str::random(8)),
            'name' => $name,
            'type' => $type,
            'effective_from' => '2020-01-01',
            'is_active' => true,
        ]);
    }

    private function manager(OrgUnit $unit, User $user, array $attributes = []): OrgUnitManager
    {
        return OrgUnitManager::create($attributes + [
            'org_unit_id' => $unit->id,
            'user_id' => $user->id,
            'assignment_type' => OrgUnitManager::TYPE_MANAGER,
            'scope' => 'DESCENDANTS',
            'order_number' => '1/2569',
            'order_date' => '2026-05-16',
            'effective_from' => today()->subMonth()->toDateString(),
        ]);
    }

    private function userIn(OrgUnit $unit, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['is_active' => true]);
        $user->forceFill(['org_unit_id' => $unit->id])->saveQuietly();

        return $user->fresh();
    }

    // ---------------------------------------------------------------- 1–2 resolver

    public function test_org_unit_manager_step_resolves_the_head_of_the_named_unit_for_every_requester(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $academic = $this->unit('ฝ่ายวิชาการ', null, 'division');
        $electrical = $this->unit('แผนกวิชาช่างไฟฟ้า', $academic, 'department');

        $resourcesDeputy = User::factory()->create(['is_active' => true]);
        $academicDeputy = User::factory()->create(['is_active' => true]);
        $this->manager($resources, $resourcesDeputy);
        $this->manager($academic, $academicDeputy);

        $teacher = $this->userIn($electrical);
        $step = ['approver_type' => ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, 'org_unit_id' => $resources->id];

        $resolver = app(ApproverResolverService::class);
        $this->assertSame([$resourcesDeputy->id], $resolver->resolveEligibleApproverIds($teacher, $step));
        $this->assertFalse($resolver->isAuthorizedApprover($academicDeputy, $teacher, $step));

        // ฝ่ายของผู้ยื่นแบบเดิมยังได้รองฯ ฝ่ายวิชาการ — สองวิธีให้คำตอบต่างกันตามเจตนา
        $division = ['approver_type' => ApprovalStep::RESOLVER_DIVISION_DIRECTOR];
        $this->assertSame([$academicDeputy->id], $resolver->resolveEligibleApproverIds($teacher, $division));
    }

    public function test_org_unit_manager_step_never_falls_back_to_role_names(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $anyDeputy = User::factory()->create(['is_active' => true]);
        $anyDeputy->assignRole(Role::findOrCreate('Deputy Director', 'web'));
        $teacher = $this->userIn($this->unit('งานทะเบียน', $resources));

        $ids = app(ApproverResolverService::class)->resolveEligibleApproverIds($teacher, [
            'approver_type' => ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, 'org_unit_id' => $resources->id,
        ]);

        $this->assertSame([], $ids);
    }

    public function test_ended_or_future_head_is_not_an_approver(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $former = User::factory()->create(['is_active' => true]);
        $future = User::factory()->create(['is_active' => true]);
        $this->manager($resources, $former, ['effective_to' => today()->subDay()->toDateString()]);
        $this->manager($resources, $future, ['effective_from' => today()->addDay()->toDateString()]);
        $teacher = $this->userIn($this->unit('งานพัสดุ', $resources));

        $ids = app(ApproverResolverService::class)->resolveEligibleApproverIds($teacher, [
            'approver_type' => ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, 'org_unit_id' => $resources->id,
        ]);

        $this->assertSame([], $ids);
    }

    public function test_role_name_fallback_can_be_switched_off(): void
    {
        $deputy = User::factory()->create(['is_active' => true]);
        $deputy->assignRole(Role::findOrCreate('Deputy Director', 'web'));
        $teacher = $this->userIn($this->unit('งานไม่มีหัวหน้า'));
        $step = ['approver_type' => ApprovalStep::RESOLVER_DIVISION_DIRECTOR];
        $resolver = app(ApproverResolverService::class);

        config(['workflow.approver_role_fallback' => true]);
        $this->assertContains($deputy->id, $resolver->resolveEligibleApproverIds($teacher, $step));

        config(['workflow.approver_role_fallback' => false]);
        $this->assertSame([], $resolver->resolveEligibleApproverIds($teacher, $step));

        // role ที่ผู้ดูแลเลือกไว้ในขั้นเองยังใช้ได้ เพราะเป็นข้อมูลที่ตั้งใจตั้ง
        $chosen = Role::findOrCreate('ผู้อนุมัติที่เลือกไว้', 'web');
        $picked = User::factory()->create(['is_active' => true]);
        $picked->assignRole($chosen);
        $this->assertSame([$picked->id], $resolver->resolveEligibleApproverIds($teacher, $step + ['role_id' => $chosen->id]));
    }

    public function test_snapshot_carries_the_target_unit(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $step = ApprovalStep::create([
            'name' => 'รองฯ ฝ่ายบริหารทรัพยากร', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, 'org_unit_id' => $resources->id,
        ]);

        $this->assertSame($resources->id, $step->snapshotAttributes()['org_unit_id']);
        $this->assertSame(ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, $step->snapshotAttributes()['approver_type']);
    }

    // ---------------------------------------------------------------- workflow admin

    public function test_workflow_admin_saves_an_org_unit_manager_step_and_rejects_a_missing_unit(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');

        $this->actingAs($this->superAdmin)->postJson('/api/admin/workflow-steps', ['steps' => [
            ['name' => 'รองฯ ฝ่ายบริหารทรัพยากร', 'approver_type' => 'ORG_UNIT_MANAGER'],
        ]])->assertStatus(422)->assertJsonValidationErrors('steps.0.org_unit_id');

        $this->actingAs($this->superAdmin)->postJson('/api/admin/workflow-steps', ['steps' => [
            ['name' => 'งานบุคลากรตรวจสอบ', 'approver_type' => 'HR_OFFICER'],
            ['name' => 'รองฯ ฝ่ายบริหารทรัพยากร', 'approver_type' => 'ORG_UNIT_MANAGER', 'org_unit_id' => $resources->id],
            ['name' => 'ผู้อำนวยการ', 'approver_type' => 'DIRECTOR'],
        ]])->assertOk();

        $this->assertDatabaseHas('approval_steps', ['step_order' => 2, 'approver_type' => 'ORG_UNIT_MANAGER', 'org_unit_id' => $resources->id]);
        // ขั้นแบบอื่นไม่เก็บหน่วยงานไว้ให้สับสน
        $this->assertDatabaseHas('approval_steps', ['step_order' => 1, 'org_unit_id' => null]);
    }

    // ---------------------------------------------------------------- 3 cancellation (Q4)

    public function test_cancellation_walks_the_original_steps_of_the_leave_even_after_the_chain_changes(): void
    {
        $first = Role::findOrCreate('ผู้ตรวจขั้นแรก', 'web');
        $second = Role::findOrCreate('ผู้อนุญาต', 'web');
        ApprovalStep::create(['name' => 'ขั้นแรก', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $first->id]);
        ApprovalStep::create(['name' => 'ผู้อนุญาต', 'step_order' => 2, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $second->id]);
        $approverOne = User::factory()->create(['is_active' => true]);
        $approverOne->assignRole($first);
        $approverTwo = User::factory()->create(['is_active' => true]);
        $approverTwo->assignRole($second);

        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบ Q4', 'code_prefix' => 'Q']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10, 'period_basis' => 'fiscal_year',
            'exclude_holidays' => true, 'allow_half_day' => true, 'is_active' => true,
        ]);
        $requester = User::factory()->create(['department_id' => Department::create(['name' => 'แผนก Q4'])->id, 'personnel_type_id' => $personnel->id, 'is_active' => true]);

        $day = now()->addWeek()->nextWeekday()->toDateString();
        $leaveId = $this->actingAs($requester)->postJson('/api/leaves', [
            'leave_type_id' => $type->id, 'start_date' => $day, 'end_date' => $day, 'reason' => 'ทดสอบ',
        ])->assertCreated()->json('leave.id');
        $this->actingAs($approverOne)->postJson("/api/approvals/{$leaveId}/approve")->assertOk();
        $this->actingAs($approverTwo)->postJson("/api/approvals/{$leaveId}/approve")->assertOk();

        // หลังใบลาอนุมัติ ผู้ดูแลแก้สายของใบลาเหลือขั้นเดียว
        ApprovalStep::query()->chain(ApprovalStep::CHAIN_LEAVE)->delete();
        ApprovalStep::create(['name' => 'ขั้นใหม่', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $second->id]);

        $this->actingAs($requester)->postJson("/api/leaves/{$leaveId}/cancel", ['cancellation_reason' => 'ภารกิจเปลี่ยน'])->assertOk();

        $steps = LeaveApproval::where('leave_id', $leaveId)->where('stage', LeaveApproval::STAGE_CANCELLATION)->orderBy('step_order')->get();
        $this->assertSame(['ขั้นแรก', 'ผู้อนุญาต'], $steps->pluck('step_name')->all());

        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leaveId}/approve-cancellation")->assertForbidden();
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leaveId}/approve-cancellation")->assertOk();
        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leaveId}/approve-cancellation")->assertOk();
        $this->assertSame('Cancelled', Leave::findOrFail($leaveId)->status);
    }

    // ---------------------------------------------------------------- 4 org structure

    public function test_org_structure_requires_its_own_permission(): void
    {
        $hr = User::factory()->create(['is_active' => true]);
        $hr->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        $this->actingAs($hr)->getJson('/api/admin/org-units')->assertForbidden();
        $this->actingAs($hr)->postJson('/api/admin/org-units', ['name' => 'ฝ่ายใหม่', 'type' => 'division', 'parent_uuid' => $this->root->uuid])->assertForbidden();

        // ผู้จัดการลำดับการอนุมัติอ่านรายการได้ แต่แก้ไม่ได้
        $workflowAdmin = User::factory()->create(['is_active' => true]);
        $workflowAdmin->givePermissionTo(Permission::findOrCreate('manage_workflow_steps', 'web'));
        $this->actingAs($workflowAdmin)->getJson('/api/admin/org-units')->assertOk();
        $this->actingAs($workflowAdmin)->postJson('/api/admin/org-units', ['name' => 'ฝ่ายใหม่', 'type' => 'division', 'parent_uuid' => $this->root->uuid])->assertForbidden();
    }

    public function test_admin_builds_divisions_moves_units_and_cannot_create_a_cycle(): void
    {
        $uuid = $this->actingAs($this->superAdmin)->postJson('/api/admin/org-units', [
            'name' => 'ฝ่ายบริหารทรัพยากร', 'type' => 'division', 'parent_uuid' => $this->root->uuid,
        ])->assertCreated()->json('uuid');
        $division = OrgUnit::where('uuid', $uuid)->firstOrFail();
        $section = $this->unit('งานบริหารและพัฒนาทรัพยากรบุคคล');

        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$section->uuid}", ['parent_uuid' => $division->uuid])->assertOk();
        $this->assertSame($division->id, $section->fresh()->parent_id);

        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$division->uuid}", ['parent_uuid' => $section->uuid])
            ->assertStatus(422)->assertJsonValidationErrors('parent_uuid');
        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$this->root->uuid}", ['parent_uuid' => $division->uuid])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'org_unit.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'org_unit.updated']);
    }

    public function test_department_linked_unit_is_renamed_only_from_the_departments_page(): void
    {
        $department = Department::create(['name' => 'แผนกวิชาช่างยนต์']);
        $unitId = DB::table('department_org_unit_map')->where('department_id', $department->id)->value('org_unit_id')
            ?? tap($this->unit('แผนกวิชาช่างยนต์', null, 'department'), fn (OrgUnit $u) => DB::table('department_org_unit_map')->insert(['department_id' => $department->id, 'org_unit_id' => $u->id]))->id;
        $unit = OrgUnit::findOrFail($unitId);

        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$unit->uuid}", ['name' => 'ชื่ออื่น'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_head_assignment_needs_an_order_reference_and_is_closed_not_deleted(): void
    {
        $division = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $deputy = User::factory()->create(['is_active' => true]);

        $this->actingAs($this->superAdmin)->postJson("/api/admin/org-units/{$division->uuid}/managers", [
            'user_uuid' => $deputy->uuid, 'assignment_type' => 'MANAGER', 'scope' => 'DESCENDANTS',
            'effective_from' => today()->toDateString(), 'order_date' => today()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('order_number');

        $managerUuid = $this->actingAs($this->superAdmin)->postJson("/api/admin/org-units/{$division->uuid}/managers", [
            'user_uuid' => $deputy->uuid, 'assignment_type' => 'MANAGER', 'scope' => 'DESCENDANTS',
            'order_number' => '88/2569', 'order_date' => today()->toDateString(), 'effective_from' => today()->toDateString(),
        ])->assertCreated()->json('uuid');

        // ซ้อนช่วงเวลาเดียวกันไม่ได้
        $this->actingAs($this->superAdmin)->postJson("/api/admin/org-units/{$division->uuid}/managers", [
            'user_uuid' => $deputy->uuid, 'assignment_type' => 'MANAGER', 'scope' => 'DESCENDANTS',
            'order_number' => '89/2569', 'order_date' => today()->toDateString(), 'effective_from' => today()->addDay()->toDateString(),
        ])->assertStatus(422);

        $this->actingAs($this->superAdmin)->postJson("/api/admin/org-unit-managers/{$managerUuid}/end", [
            'effective_to' => today()->toDateString(), 'note' => 'ย้ายตามคำสั่งที่ 90/2569',
        ])->assertOk();

        $closed = OrgUnitManager::where('uuid', $managerUuid)->firstOrFail();
        $this->assertSame(today()->toDateString(), $closed->effective_to->toDateString());
        $this->assertSame('88/2569', $closed->order_number);
        $this->assertSame($this->superAdmin->id, $closed->ended_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'org_unit.manager_assigned']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'org_unit.manager_ended']);
    }

    public function test_unit_used_by_people_heads_or_approval_steps_cannot_be_deactivated(): void
    {
        $division = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        ApprovalStep::create(['name' => 'รองฯ', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, 'org_unit_id' => $division->id]);

        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$division->uuid}", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');

        $empty = $this->unit('งานที่เลิกแล้ว');
        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$empty->uuid}", ['is_active' => false])->assertOk();
        $this->assertFalse($empty->fresh()->is_active);
    }

    // ---------------------------------------------------------------- 5 separation

    public function test_separation_suspends_the_account_and_closes_every_source_of_authority(): void
    {
        $division = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $deputy = User::factory()->create(['is_active' => true]);
        $director = User::factory()->create(['is_active' => true]);
        $head = $this->manager($division, $deputy);
        $deputy->createToken('t');

        $delegation = UserDelegation::create([
            'user_id' => $director->id, 'delegate_id' => $deputy->id, 'delegation_type' => UserDelegation::TYPE_ON_BEHALF,
            'order_number' => '45/2569', 'order_date' => '2026-10-01',
            'start_date' => today()->subDay(), 'end_date' => today()->addMonth(),
            'is_active' => true, 'status' => UserDelegation::STATUS_APPROVED,
        ]);
        DB::table('permission_scopes')->insert([
            'model_type' => $deputy->getMorphClass(), 'model_id' => $deputy->id,
            'permission_name' => 'leave.report.view', 'scope' => 'ORGANIZATION',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)->postJson("/api/admin/users/{$deputy->id}/separate", [
            'separated_at' => today()->toDateString(), 'reason' => 'ย้ายไปวิทยาลัยอื่น ตามคำสั่งที่ 99/2569',
        ])->assertOk()->assertJsonPath('result.managers_ended', 1)->assertJsonPath('result.delegations_revoked', 1);

        $deputy->refresh();
        $this->assertFalse($deputy->is_active);
        $this->assertSame(today()->toDateString(), $deputy->separated_at->toDateString());
        $this->assertSame(0, $deputy->tokens()->count());
        $this->assertSame(today()->toDateString(), $head->fresh()->effective_to->toDateString());
        $this->assertSame(UserDelegation::STATUS_REVOKED, $delegation->fresh()->status);
        $this->assertSame(0, DB::table('permission_scopes')->where('model_id', $deputy->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.separated']);
        $this->assertDatabaseHas('users', ['id' => $deputy->id]);

        // วันพรุ่งนี้ไม่เป็นผู้อนุมัติของฝ่ายแล้ว
        $teacher = $this->userIn($this->unit('งานพัสดุ', $division));
        $this->assertSame([], app(ApproverResolverService::class)->resolveEligibleApproverIds($teacher, [
            'approver_type' => ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, 'org_unit_id' => $division->id,
        ], today()->addDay()->toDateString()));
    }

    public function test_hr_cannot_separate_a_super_admin_or_themselves(): void
    {
        $hr = User::factory()->create(['is_active' => true]);
        $hr->givePermissionTo(Permission::findOrCreate('system.user.manage', 'web'));

        $this->actingAs($hr)->postJson("/api/admin/users/{$this->superAdmin->id}/separate", [
            'separated_at' => today()->toDateString(), 'reason' => 'ทดสอบ',
        ])->assertForbidden();
        $this->actingAs($hr)->postJson("/api/admin/users/{$hr->id}/separate", [
            'separated_at' => today()->toDateString(), 'reason' => 'ทดสอบ',
        ])->assertStatus(422);

        $this->assertTrue($this->superAdmin->fresh()->is_active);
    }

    public function test_reactivating_a_separated_account_clears_the_separation(): void
    {
        $teacher = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->superAdmin)->postJson("/api/admin/users/{$teacher->id}/separate", [
            'separated_at' => today()->toDateString(), 'reason' => 'หมดสัญญาจ้าง',
        ])->assertOk();

        $this->actingAs($this->superAdmin)->postJson("/api/admin/users/{$teacher->id}/toggle-status")->assertOk();

        $teacher->refresh();
        $this->assertTrue($teacher->is_active);
        $this->assertNull($teacher->separated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reinstated']);
    }

    // ---------------------------------------------------------------- 6 annual review

    public function test_annual_review_is_generated_by_an_admin_and_signed_off_by_the_director_only(): void
    {
        Storage::fake('local');
        $service = app(AccessReviewService::class);
        $director = User::factory()->create(['is_active' => true]);
        $this->manager($this->root, $director);
        $teacher = User::factory()->create(['is_active' => true]);

        try {
            $service->generate($teacher, '2569');
            $this->fail('ผู้ไม่มีสิทธิ์สร้างรายงานได้');
        } catch (InvalidArgumentException) {
        }

        $result = $service->generate($this->superAdmin, '2569');
        Storage::disk('local')->assertExists('access-reviews/2569.csv');
        $this->assertGreaterThanOrEqual(1, $result['privileged_count']);
        $this->assertStringContainsString($this->superAdmin->email, Storage::disk('local')->get('access-reviews/2569.csv'));

        foreach ([$this->superAdmin, $teacher] as $notDirector) {
            try {
                $service->signOff($notDirector, '2569');
                $this->fail('ผู้ที่ไม่ใช่ผู้อำนวยการรับรองได้');
            } catch (InvalidArgumentException) {
            }
        }

        $service->signOff($director, '2569', 'ทบทวนแล้ว');
        $this->assertDatabaseHas('access_reviews', ['academic_year' => '2569', 'signed_off_by' => $director->id]);

        $this->expectException(InvalidArgumentException::class);
        $service->generate($this->superAdmin, '2569');
    }

    public function test_login_records_last_login_time(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => 'secret-password-123']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-password-123'])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
