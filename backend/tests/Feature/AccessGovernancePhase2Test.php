<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\OrgUnit;
use App\Models\OrgUnitManager;
use App\Models\OrgUnitMember;
use App\Models\Overtime\OvertimeOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Services\DocumentEngine\LeaveFormFieldMap;
use App\Services\OrgScopeResolver;
use App\Services\RoleGrantService;
use App\Services\Workflow\ApproverResolverService;
use App\Services\Workflow\Assignee\OvertimeAssigneeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ACCESS-GOV-01 เฟสสอง (docs/ACCESS-GOVERNANCE-2569.md หัวข้อ 5)
 *
 *  B2 แบบฟอร์มและสาย OT ไม่ตัดสินจากชื่อบทบาท
 *  C1 เจ้าหน้าที่ประจำงานหลายงาน (ระเบียบฯ 2569 ข้อ 13 วรรคสอง)
 *  C3 ทะเบียนการมอบบทบาทพร้อมเลขคำสั่งและวันหมดอายุ
 *  B5 บทบาทที่ขาด: HR Officer / Auditor / Overtime Operator
 */
class AccessGovernancePhase2Test extends TestCase
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
            'uuid' => (string) Str::uuid(), 'parent_id' => ($parent ?? $this->root)->id,
            'code' => 'T-'.Str::upper(Str::random(8)), 'name' => $name, 'type' => $type,
            'effective_from' => '2020-01-01', 'is_active' => true,
        ]);
    }

    private function appointHead(OrgUnit $unit, User $user): OrgUnitManager
    {
        return OrgUnitManager::create([
            'org_unit_id' => $unit->id, 'user_id' => $user->id, 'assignment_type' => OrgUnitManager::TYPE_MANAGER,
            'scope' => 'DESCENDANTS', 'order_number' => '1/2569', 'order_date' => '2026-05-16',
            'effective_from' => today()->subMonth()->toDateString(),
        ]);
    }

    private function member(OrgUnit $unit, User $user, array $attributes = []): OrgUnitMember
    {
        return OrgUnitMember::create($attributes + [
            'org_unit_id' => $unit->id, 'user_id' => $user->id, 'order_number' => '7/2569', 'order_date' => '2026-05-16',
            'effective_from' => today()->subMonth()->toDateString(),
        ]);
    }

    private function userIn(OrgUnit $unit): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['org_unit_id' => $unit->id])->saveQuietly();

        return $user->fresh();
    }

    // ---------------------------------------------------------------- B2 forms

    public function test_signer_capacity_comes_from_the_step_not_the_role_name(): void
    {
        $division = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $section = $this->unit('งานพัสดุ', $division);

        $this->assertSame(ApprovalStep::CAPACITY_CHECKER, ApprovalStep::capacityOf(ApprovalStep::RESOLVER_HR_OFFICER, null, null));
        $this->assertSame(ApprovalStep::CAPACITY_DEPUTY, ApprovalStep::capacityOf(ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, null, $division->id));
        $this->assertSame(ApprovalStep::CAPACITY_SUPERVISOR, ApprovalStep::capacityOf(ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, null, $section->id));
        $this->assertSame(ApprovalStep::CAPACITY_DIRECTOR, ApprovalStep::capacityOf(ApprovalStep::RESOLVER_ORG_UNIT_MANAGER, null, $this->root->id));
        $this->assertSame(ApprovalStep::CAPACITY_DIRECTOR, ApprovalStep::capacityOf(ApprovalStep::RESOLVER_DIRECTOR, null, null));
        // ขั้นแบบ ROLE ของระบบเดิมยังอ่านได้
        $this->assertSame(ApprovalStep::CAPACITY_CHECKER, ApprovalStep::capacityOf(ApprovalStep::RESOLVER_ROLE, 'HR Head', null));
        $this->assertNull(ApprovalStep::capacityOf(ApprovalStep::RESOLVER_ROLE, 'บทบาทอื่น', null));
    }

    public function test_form_finds_the_hr_checker_block_of_a_chain_without_role_names(): void
    {
        $blocks = [
            ['capacity' => ApprovalStep::CAPACITY_SUPERVISOR, 'role_name' => ''],
            ['capacity' => ApprovalStep::CAPACITY_CHECKER, 'role_name' => ''],
            ['capacity' => ApprovalStep::CAPACITY_DEPUTY, 'role_name' => ''],
            ['capacity' => ApprovalStep::CAPACITY_DIRECTOR, 'role_name' => ''],
        ];

        $this->assertSame(['checker' => 1, 'commander' => 2, 'authorizer' => 3], LeaveFormFieldMap::roles($blocks));
        // บล็อกเก่าที่ไม่มี capacity ยังใช้ชื่อบทบาทได้
        $this->assertTrue(LeaveFormFieldMap::isChecker(['role_name' => 'HR Head']));
    }

    // ---------------------------------------------------------------- B2 overtime

    private function otInstance(): WorkflowInstance
    {
        return new WorkflowInstance(['subject_type' => (new OvertimeOrder)->getMorphClass(), 'subject_id' => 1]);
    }

    public function test_overtime_permit_and_approval_follow_unit_heads_when_configured(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');
        $deputy = User::factory()->create(['is_active' => true]);
        $director = User::factory()->create(['is_active' => true]);
        $this->appointHead($resources, $deputy);
        $this->appointHead($this->root, $director);
        $resolver = app(OvertimeAssigneeResolver::class);

        // ยังไม่ตั้งหน่วยงาน → ให้ engine ใช้บทบาทตามเดิม
        $this->assertNull($resolver->candidates($this->otInstance(), new WorkflowStep(['code' => 'overtime_permit'])));

        Setting::updateOrCreate(['key' => OvertimeAssigneeResolver::PERMIT_UNIT_SETTING], ['value' => (string) $resources->id, 'type' => 'string']);
        $this->assertSame([$deputy->id], $resolver->candidates($this->otInstance(), new WorkflowStep(['code' => 'overtime_permit'])));
        $this->assertSame([$director->id], $resolver->candidates($this->otInstance(), new WorkflowStep(['code' => 'overtime_approval'])));
    }

    public function test_overtime_resolver_fails_closed_when_fallback_is_off(): void
    {
        config(['workflow.approver_role_fallback' => false]);

        $this->assertSame([], app(OvertimeAssigneeResolver::class)->candidates($this->otInstance(), new WorkflowStep(['code' => 'overtime_permit'])));
    }

    public function test_admin_sets_the_overtime_permit_unit(): void
    {
        $resources = $this->unit('ฝ่ายบริหารทรัพยากร', null, 'division');

        $this->actingAs($this->superAdmin)->putJson('/api/admin/org-structure/overtime-permit-unit', ['org_unit_uuid' => $resources->uuid])->assertOk();
        $this->assertSame((string) $resources->id, Setting::where('key', OvertimeAssigneeResolver::PERMIT_UNIT_SETTING)->value('value'));
        $this->actingAs($this->superAdmin)->getJson('/api/admin/org-units')->assertJsonPath('overtime_permit_unit_uuid', $resources->uuid);

        $hr = User::factory()->create(['is_active' => true]);
        $hr->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));
        $this->actingAs($hr)->putJson('/api/admin/org-structure/overtime-permit-unit', ['org_unit_uuid' => null])->assertForbidden();
    }

    // ---------------------------------------------------------------- C1 membership

    public function test_staff_membership_widens_unit_scope_but_does_not_make_an_approver(): void
    {
        $academic = $this->unit('แผนกวิชาช่างไฟฟ้า', null, 'department');
        $supplies = $this->unit('งานพัสดุ');
        $finance = $this->unit('งานการเงิน');
        $teacher = $this->userIn($academic);
        $this->member($supplies, $teacher);
        $this->member($finance, $teacher, ['effective_to' => today()->subDay()->toDateString()]);

        $visible = app(OrgScopeResolver::class)->visibleOrgUnitIds($teacher, 'leave.report.view', OrgScopeResolver::UNIT);
        sort($visible);
        $expected = [$academic->id, $supplies->id];
        sort($expected);
        $this->assertSame($expected, $visible);

        $colleague = $this->userIn($supplies);
        $this->assertNotContains($teacher->id, app(ApproverResolverService::class)->resolveEligibleApproverIds($colleague, ['approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD]));
    }

    public function test_member_assignment_needs_an_order_and_ends_without_delete(): void
    {
        $supplies = $this->unit('งานพัสดุ');
        $teacher = User::factory()->create(['is_active' => true]);

        $this->actingAs($this->superAdmin)->postJson("/api/admin/org-units/{$supplies->uuid}/members", [
            'user_uuid' => $teacher->uuid, 'order_date' => today()->toDateString(), 'effective_from' => today()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('order_number');

        $uuid = $this->actingAs($this->superAdmin)->postJson("/api/admin/org-units/{$supplies->uuid}/members", [
            'user_uuid' => $teacher->uuid, 'order_number' => '12/2569', 'order_date' => today()->toDateString(), 'effective_from' => today()->toDateString(),
        ])->assertCreated()->json('uuid');

        $this->actingAs($this->superAdmin)->getJson('/api/admin/org-units')->assertOk();
        $this->actingAs($this->superAdmin)->putJson("/api/admin/org-units/{$supplies->uuid}", ['is_active' => false])->assertStatus(422);

        $this->actingAs($this->superAdmin)->postJson("/api/admin/org-unit-members/{$uuid}/end", ['effective_to' => today()->toDateString()])->assertOk();
        $this->assertSame(today()->toDateString(), OrgUnitMember::where('uuid', $uuid)->first()->effective_to->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'org_unit.member_ended']);
    }

    public function test_separation_ends_staff_memberships(): void
    {
        $teacher = User::factory()->create(['is_active' => true]);
        $membership = $this->member($this->unit('งานพัสดุ'), $teacher);

        $this->actingAs($this->superAdmin)->postJson("/api/admin/users/{$teacher->id}/separate", [
            'separated_at' => today()->toDateString(), 'reason' => 'ย้าย',
        ])->assertOk()->assertJsonPath('result.memberships_ended', 1);

        $this->assertSame(today()->toDateString(), $membership->fresh()->effective_to->toDateString());
    }

    // ---------------------------------------------------------------- C3 role grants

    public function test_role_change_from_the_user_form_records_order_and_expiry(): void
    {
        $role = Role::findOrCreate('HR Head', 'web');
        $department = Department::create(['name' => 'งานบุคคล']);
        $user = User::factory()->create(['is_active' => true, 'department_id' => $department->id]);

        $this->actingAs($this->superAdmin)->putJson("/api/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $role->id, 'department_id' => $department->id,
            'role_order_number' => '55/2569', 'role_expires_at' => today()->addYear()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('user_role_grants', ['user_id' => $user->id, 'role_id' => $role->id, 'order_number' => '55/2569', 'ended_at' => null]);
        $this->assertSame('55/2569', $user->fresh()->currentRoleGrant->order_number);

        // บันทึกซ้ำด้วยค่าเดิมไม่สร้างแถวใหม่
        $this->actingAs($this->superAdmin)->putJson("/api/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $role->id, 'department_id' => $department->id,
            'role_order_number' => '55/2569', 'role_expires_at' => today()->addYear()->toDateString(),
        ])->assertOk();
        $this->assertSame(1, DB::table('user_role_grants')->where('user_id', $user->id)->count());

        $this->actingAs($this->superAdmin)->putJson("/api/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $role->id, 'department_id' => $department->id,
            'role_expires_at' => today()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('role_expires_at');
    }

    public function test_expired_role_returns_to_the_base_role(): void
    {
        $base = Role::findOrCreate('Employee', 'web');
        $hrHead = Role::findOrCreate('HR Head', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$hrHead->name]);
        app(RoleGrantService::class)->record($user, $hrHead, $this->superAdmin, '9/2569', today()->addDays(10)->toDateString());

        $this->assertSame(0, app(RoleGrantService::class)->expireDue(today()->toDateString()));
        $this->assertTrue($user->fresh()->hasRole('HR Head'));

        $this->artisan('access:expire-role-grants')->assertSuccessful();
        $this->assertSame(1, app(RoleGrantService::class)->expireDue(today()->addDays(11)->toDateString()));

        $user = $user->fresh();
        $this->assertFalse($user->hasRole('HR Head'));
        $this->assertTrue($user->hasRole($base->name));
        $this->assertSame('Employee', $user->currentRoleGrant->role_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.role_grant_expired']);
    }

    // ---------------------------------------------------------------- B5 roles

    public function test_recommended_roles_exist_with_least_privilege(): void
    {
        $hrOfficer = Role::findByName('HR Officer', 'web');
        $auditor = Role::findByName('Auditor', 'web');
        $operator = Role::findByName('Overtime Operator', 'web');

        $this->assertTrue($hrOfficer->hasPermissionTo('attendance.view_all'));
        $this->assertFalse($hrOfficer->hasPermissionTo('leave.management.manage'));
        $this->assertFalse($hrOfficer->hasPermissionTo('admin.users.manage_permissions'));
        $this->assertTrue($auditor->hasPermissionTo('leave.report.view'));
        $this->assertFalse($auditor->permissions->pluck('name')->contains(fn ($n) => str_contains($n, 'manage') || str_contains($n, 'approve') || $n === 'system.user.manage'));
        $this->assertTrue($operator->hasPermissionTo('overtime.operation.execute'));
        $this->assertFalse($operator->hasPermissionTo('overtime.management.review'));
    }
}
