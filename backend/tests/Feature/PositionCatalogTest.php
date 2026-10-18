<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\PersonnelAssignment;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ORG-ACCESS-02 — ทะเบียนตำแหน่งและกลุ่มบุคลากร (Q7)
 *
 * สิ่งที่ต้องพิสูจน์:
 *  1. migration เพิ่มอย่างเดียว ข้อความตำแหน่งเดิมไม่หาย และ position_id เริ่มเป็น null
 *  2. แก้ทะเบียนต้องมีสิทธิ์จัดการผู้ใช้ ทุกการเปลี่ยนแปลง audit และไม่มีการลบ
 *  3. ผูกตำแหน่งไม่เขียนทับประวัติ ข้อความในแบบฟอร์มเป็นชื่อ ณ ตอนผูก
 *  4. รายงานแยกกลุ่มได้และยังเคารพขอบเขตหน่วยงาน (IDOR)
 *  5. ตำแหน่งไม่ให้สิทธิ์ใด ๆ
 */
class PositionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Role $employeeRole;

    private Department $department;

    private int $unitA;

    private int $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('system.user.manage', 'web');
        Permission::findOrCreate('view_users', 'web');
        Permission::findOrCreate('leave.report.view', 'web');
        $this->employeeRole = Role::findOrCreate('Employee', 'web');
        $this->department = Department::create(['name' => 'แผนกทดสอบ']);

        $root = $this->orgUnit('ROOT', null);
        $this->unitA = $this->orgUnit('DIV-A', $root);
        $this->unitB = $this->orgUnit('DIV-B', $root);

        $this->admin = User::factory()->create(['email' => 'admin@test.local', 'is_active' => true]);
        $this->admin->givePermissionTo(['system.user.manage', 'view_users']);
    }

    // ------------------------------------------------------------- migration

    public function test_migration_is_additive_and_seeds_three_groups(): void
    {
        $this->assertTrue(Schema::hasColumns('personnel_assignments', ['position', 'position_id']));
        $this->assertSame(['EXECUTIVE', 'TEACHER', 'STAFF'], PositionGroup::orderBy('sort_order')->pluck('code')->all());
        $this->assertSame('ผู้บริหาร', PositionGroup::where('code', 'EXECUTIVE')->value('name'));
        $this->assertTrue(Position::where('code', 'CONTRACT_TEACHER')->whereHas('group', fn ($q) => $q->where('code', 'TEACHER'))->exists());
        $this->assertTrue(Position::where('code', 'HOURLY_TEACHER')->exists());
        $this->assertTrue(Position::where('code', 'STAFF_SUPPLIES')->whereHas('group', fn ($q) => $q->where('code', 'STAFF'))->exists());
    }

    public function test_existing_free_text_positions_keep_their_text_and_start_unlinked(): void
    {
        $user = $this->staff($this->unitA, 'ครูชำนาญการ');

        $assignment = PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->first();
        $this->assertSame('ครูชำนาญการ', $assignment->position);
        $this->assertNull($assignment->position_id);
    }

    // ------------------------------------------------------- reading the catalog

    public function test_catalog_requires_login_and_exposes_no_internal_ids(): void
    {
        $this->getJson('/api/positions')->assertUnauthorized();

        $response = $this->actingAs($this->staff($this->unitA))->getJson('/api/positions')->assertOk();

        $first = $response->json('0');
        $this->assertArrayHasKey('uuid', $first);
        $this->assertArrayNotHasKey('id', $first);
        $this->assertArrayNotHasKey('id', $first['positions'][0]);
    }

    // ------------------------------------------------------ editing the catalog

    public function test_admin_creates_and_updates_positions_with_audit(): void
    {
        $group = PositionGroup::where('code', 'STAFF')->first();

        $uuid = $this->actingAs($this->admin)->postJson('/api/admin/positions', [
            'code' => 'STAFF_LIBRARY',
            'name' => 'เจ้าหน้าที่ห้องสมุด',
            'group_uuid' => $group->uuid,
        ])->assertCreated()->json('uuid');

        $position = Position::where('uuid', $uuid)->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'position.created', 'resource_id' => (string) $position->id, 'actor_id' => $this->admin->id]);

        $this->actingAs($this->admin)->putJson("/api/admin/positions/{$uuid}", [
            'code' => 'STAFF_LIBRARY',
            'name' => 'เจ้าหน้าที่งานวิทยบริการและห้องสมุด',
            'group_uuid' => $group->uuid,
            'is_active' => false,
        ])->assertOk();

        $this->assertFalse($position->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'position.updated', 'resource_id' => (string) $position->id]);
    }

    public function test_admin_creates_group_with_audit_and_cannot_deactivate_group_with_active_positions(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/position-groups', [
            'code' => 'SUPPORT',
            'name' => 'บุคลากรสนับสนุน',
        ])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'position_group.created']);

        $teacher = PositionGroup::where('code', 'TEACHER')->first();
        $this->actingAs($this->admin)->putJson("/api/admin/position-groups/{$teacher->uuid}", [
            'code' => 'TEACHER',
            'name' => 'ครู',
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->assertTrue($teacher->fresh()->is_active);
    }

    public function test_catalog_cannot_be_changed_without_user_management_permission(): void
    {
        $group = PositionGroup::where('code', 'STAFF')->first();
        $position = Position::where('code', 'STAFF_SUPPLIES')->first();
        $clerk = $this->staff($this->unitA);

        $this->actingAs($clerk)->postJson('/api/admin/positions', ['code' => 'X', 'name' => 'x', 'group_uuid' => $group->uuid])->assertForbidden();
        $this->actingAs($clerk)->putJson("/api/admin/positions/{$position->uuid}", ['code' => 'STAFF_SUPPLIES', 'name' => 'x', 'group_uuid' => $group->uuid, 'is_active' => false])->assertForbidden();
        $this->actingAs($clerk)->postJson('/api/admin/position-groups', ['code' => 'X', 'name' => 'x'])->assertForbidden();

        $this->assertTrue($position->fresh()->is_active);
    }

    public function test_there_is_no_delete_endpoint(): void
    {
        $position = Position::where('code', 'STAFF_SUPPLIES')->first();

        $this->actingAs($this->admin)->deleteJson("/api/admin/positions/{$position->uuid}")->assertStatus(405);
        $this->assertDatabaseHas('positions', ['id' => $position->id]);
    }

    public function test_code_must_be_unique_and_upper_snake_case(): void
    {
        $group = PositionGroup::where('code', 'STAFF')->first();

        $this->actingAs($this->admin)->postJson('/api/admin/positions', ['code' => 'STAFF_SUPPLIES', 'name' => 'ซ้ำ', 'group_uuid' => $group->uuid])
            ->assertJsonValidationErrors('code');
        $this->actingAs($this->admin)->postJson('/api/admin/positions', ['code' => 'staff-x', 'name' => 'ผิดรูปแบบ', 'group_uuid' => $group->uuid])
            ->assertJsonValidationErrors('code');
    }

    // ---------------------------------------------------- assigning to people

    public function test_assigning_a_position_closes_old_assignment_and_sets_form_text(): void
    {
        $user = $this->staff($this->unitA, 'ครูชำนาญการ', now()->subMonth()->toDateString());
        $position = Position::where('code', 'TEACHER_INSTRUCTOR')->first();

        $this->updateUser($user, ['position_uuid' => $position->uuid])->assertOk();

        $history = PersonnelAssignment::where('user_id', $user->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $history);
        $this->assertSame('ครูชำนาญการ', $history[0]->position);
        $this->assertSame(now()->subDay()->toDateString(), $history[0]->effective_to->toDateString());
        $this->assertNull($history[1]->effective_to);
        $this->assertSame($position->id, (int) $history[1]->position_id);
        $this->assertSame('ครูผู้สอน', $history[1]->position);
        $this->assertSame($this->unitA, (int) $history[1]->org_unit_id);
        $this->assertSame('ครูผู้สอน', $user->profile()->value('position'));
        $this->assertSame('ครูผู้สอน', $user->fresh()->position);

        $this->assertDatabaseHas('audit_logs', ['action' => 'personnel.position_assigned', 'resource_id' => (string) $user->id, 'actor_id' => $this->admin->id]);
    }

    public function test_assignment_that_started_today_is_updated_in_place(): void
    {
        $user = $this->staff($this->unitA, 'เจ้าหน้าที่');
        $position = Position::where('code', 'STAFF_FINANCE')->first();

        $this->updateUser($user, ['position_uuid' => $position->uuid])->assertOk();

        $this->assertSame(1, PersonnelAssignment::where('user_id', $user->id)->count());
        $this->assertSame($position->id, (int) PersonnelAssignment::where('user_id', $user->id)->value('position_id'));
    }

    public function test_saving_without_change_does_not_create_history(): void
    {
        $user = $this->staff($this->unitA, 'ครู', now()->subMonth()->toDateString());
        $position = Position::where('code', 'TEACHER')->first();

        $this->updateUser($user, ['position_uuid' => $position->uuid])->assertOk();
        $this->updateUser($user->fresh(), ['position_uuid' => $position->uuid])->assertOk();

        $this->assertSame(2, PersonnelAssignment::where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'personnel.position_assigned')->count());
    }

    public function test_inactive_position_cannot_be_newly_assigned_but_current_holder_can_be_saved(): void
    {
        $position = Position::where('code', 'STAFF_REGISTRAR')->first();
        $holder = $this->staff($this->unitA);
        $this->updateUser($holder, ['position_uuid' => $position->uuid])->assertOk();

        $position->update(['is_active' => false]);

        $this->updateUser($holder->fresh(), ['position_uuid' => $position->uuid])->assertOk();
        $this->updateUser($this->staff($this->unitA), ['position_uuid' => $position->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('position_uuid');
    }

    public function test_renaming_a_position_does_not_rewrite_history(): void
    {
        $user = $this->staff($this->unitA);
        $position = Position::where('code', 'STAFF_SUPPLIES')->first();
        $this->updateUser($user, ['position_uuid' => $position->uuid])->assertOk();

        $position->update(['name' => 'เจ้าหน้าที่งานพัสดุ (ชื่อใหม่)']);

        $this->assertSame('เจ้าหน้าที่งานพัสดุ', PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->value('position'));
    }

    public function test_moving_unit_keeps_catalog_position(): void
    {
        $user = $this->staff($this->unitA, null, now()->subMonth()->toDateString());
        $position = Position::where('code', 'TEACHER')->first();
        $this->updateUser($user, ['position_uuid' => $position->uuid])->assertOk();

        DB::table('personnel_assignments')->where('user_id', $user->id)->whereNull('effective_to')->update(['effective_from' => now()->subWeek()->toDateString()]);
        $user->fresh()->forceFill(['org_unit_id' => $this->unitB])->save();

        $current = PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->first();
        $this->assertSame($this->unitB, (int) $current->org_unit_id);
        $this->assertSame($position->id, (int) $current->position_id);
    }

    public function test_position_grants_no_permission(): void
    {
        $user = $this->staff($this->unitA);
        $this->updateUser($user, ['position_uuid' => Position::where('code', 'DIRECTOR')->value('uuid')])->assertOk();

        $user = $user->fresh();
        $this->assertFalse($user->can('leave.report.view'));
        $this->assertFalse($user->can('system.user.manage'));
        $this->actingAs($user)->getJson('/api/reports/government-leave-register?from=2020-01-01&to=2030-12-31')->assertForbidden();
    }

    // ------------------------------------------- HR Head (system.position.manage)

    public function test_hr_head_role_gets_position_permission_but_not_user_management(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $hrHead = Role::findByName('HR Head', 'web');

        $this->assertTrue($hrHead->hasPermissionTo('system.position.manage'));
        $this->assertFalse($hrHead->hasPermissionTo('system.user.manage'));
        $this->assertFalse($hrHead->hasPermissionTo('edit_users'));
    }

    public function test_migration_grants_position_permission_to_existing_hr_head_role(): void
    {
        $role = Role::findOrCreate('HR Head', 'web');
        $this->assertFalse($role->fresh()->hasPermissionTo(Permission::findOrCreate('system.position.manage', 'web')));

        (require database_path('migrations/2026_10_17_110000_grant_position_management_to_hr_head.php'))->up();

        $this->assertTrue($role->fresh()->hasPermissionTo('system.position.manage'));
    }

    public function test_hr_can_manage_catalog_and_assign_positions_with_audit(): void
    {
        $hr = $this->hrOfficer();
        $target = $this->staff($this->unitA, null, now()->subMonth()->toDateString());
        $group = PositionGroup::where('code', 'STAFF')->first();

        $this->actingAs($hr)->postJson('/api/admin/positions', ['code' => 'STAFF_LIBRARY', 'name' => 'เจ้าหน้าที่ห้องสมุด', 'group_uuid' => $group->uuid])
            ->assertCreated();

        $position = Position::where('code', 'STAFF_LIBRARY')->first();
        $this->actingAs($hr)->putJson("/api/admin/users/{$target->uuid}/position", ['position_uuid' => $position->uuid])
            ->assertOk()
            ->assertJsonPath('position.uuid', $position->uuid)
            ->assertJsonPath('position_text', 'เจ้าหน้าที่ห้องสมุด');

        $this->assertSame($position->id, (int) PersonnelAssignment::where('user_id', $target->id)->whereNull('effective_to')->value('position_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'personnel.position_assigned', 'resource_id' => (string) $target->id, 'actor_id' => $hr->id]);

        // ล้างตำแหน่งในทะเบียนได้ ข้อความเดิมยังอยู่
        $this->actingAs($hr)->putJson("/api/admin/users/{$target->uuid}/position", ['position_uuid' => null])->assertOk();
        $current = PersonnelAssignment::where('user_id', $target->id)->whereNull('effective_to')->first();
        $this->assertNull($current->position_id);
        $this->assertSame('เจ้าหน้าที่ห้องสมุด', $current->position);
    }

    public function test_hr_cannot_use_position_permission_to_change_roles_or_permissions(): void
    {
        $hr = $this->hrOfficer();
        $superAdmin = Role::findOrCreate('Super Admin', 'web');

        // แก้ข้อมูลผู้ใช้ (ซึ่งรับ role_id / custom_permissions) ไม่ได้ — รวมถึงตัวเอง
        $this->actingAs($hr)->putJson("/api/admin/users/{$hr->id}", [
            'name' => $hr->name,
            'email' => $hr->email,
            'role_id' => $superAdmin->id,
            'department_id' => $this->department->id,
            'custom_permissions' => ['system.user.manage'],
        ])->assertForbidden();
        $this->actingAs($hr)->putJson("/api/admin/users/{$hr->id}/permissions", ['permissions' => ['system.user.manage']])->assertForbidden();

        // endpoint ผูกตำแหน่งไม่รับฟิลด์บทบาท/สิทธิ์ — ส่งมาก็ไม่มีผล
        $this->actingAs($hr)->putJson("/api/admin/users/{$hr->uuid}/position", [
            'position_uuid' => Position::where('code', 'DIRECTOR')->value('uuid'),
            'role_id' => $superAdmin->id,
            'custom_permissions' => ['system.user.manage'],
        ])->assertOk();

        $hr = $hr->fresh();
        $this->assertFalse($hr->hasRole('Super Admin'));
        $this->assertFalse($hr->hasPermissionTo('system.user.manage'));
        $this->assertFalse($hr->can('system.user.manage'));
    }

    public function test_assign_endpoint_requires_permission_and_valid_position(): void
    {
        $target = $this->staff($this->unitA);
        $position = Position::where('code', 'TEACHER')->first();

        $this->actingAs($this->staff($this->unitA))->putJson("/api/admin/users/{$target->uuid}/position", ['position_uuid' => $position->uuid])->assertForbidden();

        $hr = $this->hrOfficer();
        $this->actingAs($hr)->putJson("/api/admin/users/{$target->uuid}/position", [])->assertJsonValidationErrors('position_uuid');
        $this->actingAs($hr)->putJson("/api/admin/users/{$target->uuid}/position", ['position_uuid' => (string) Str::uuid()])->assertJsonValidationErrors('position_uuid');

        $position->update(['is_active' => false]);
        $this->actingAs($hr)->putJson("/api/admin/users/{$target->uuid}/position", ['position_uuid' => $position->uuid])->assertJsonValidationErrors('position_uuid');

        $this->assertNull(PersonnelAssignment::where('user_id', $target->id)->whereNull('effective_to')->value('position_id'));
    }

    public function test_hr_can_run_match_command(): void
    {
        $hr = $this->hrOfficer();
        $exact = $this->staff($this->unitA, 'ครูผู้สอน', now()->subMonth()->toDateString());

        $this->artisan('personnel:match-positions', ['--by' => $hr->email])->assertExitCode(0);

        $this->assertNotNull(PersonnelAssignment::where('user_id', $exact->id)->whereNull('effective_to')->value('position_id'));
    }

    // ---------------------------------------------------------------- reports

    public function test_government_register_filters_by_group_and_still_respects_unit_scope(): void
    {
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);
        $teacherA = $this->staff($this->unitA);
        $staffA = $this->staff($this->unitA);
        $teacherB = $this->staff($this->unitB);
        $this->updateUser($teacherA, ['position_uuid' => Position::where('code', 'TEACHER')->value('uuid')])->assertOk();
        $this->updateUser($staffA, ['position_uuid' => Position::where('code', 'STAFF_FINANCE')->value('uuid')])->assertOk();
        $this->updateUser($teacherB, ['position_uuid' => Position::where('code', 'TEACHER')->value('uuid')])->assertOk();
        foreach ([$teacherA, $staffA, $teacherB] as $owner) {
            $this->leave($owner, $type);
        }

        $teacherGroup = PositionGroup::where('code', 'TEACHER')->value('uuid');
        $query = '/api/reports/government-leave-register?from=2020-01-01&to=2030-12-31&position_group='.$teacherGroup;

        // ทั้งองค์กร: เห็นครูทั้งสองหน่วยงาน ไม่เห็นเจ้าหน้าที่
        $hr = $this->staff($this->unitA);
        $hr->givePermissionTo('leave.report.view');
        $owners = collect($this->actingAs($hr)->getJson($query)->assertOk()->json('data'))->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$teacherA->id, $teacherB->id])->sort()->values()->all(), $owners);

        // หัวหน้าหน่วยงาน A: ตัวกรองกลุ่มต้องไม่เปิดให้เห็นหน่วยงาน B
        $head = $this->staff($this->unitA);
        $head->givePermissionTo('leave.report.view');
        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(), 'org_unit_id' => $this->unitA, 'user_id' => $head->id,
            'assignment_type' => 'MANAGER', 'scope' => OrgScopeResolver::UNIT,
            'effective_from' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $owners = collect($this->actingAs($head)->getJson($query)->assertOk()->json('data'))->pluck('user_id')->all();
        $this->assertSame([$teacherA->id], $owners);
    }

    public function test_register_rejects_unknown_group(): void
    {
        $hr = $this->staff($this->unitA);
        $hr->givePermissionTo('leave.report.view');

        $this->actingAs($hr)->getJson('/api/reports/government-leave-register?from=2020-01-01&to=2030-12-31&position_group='.Str::uuid())
            ->assertUnprocessable()->assertJsonValidationErrors('position_group');
    }

    public function test_personnel_report_has_group_column_and_filter(): void
    {
        Permission::findOrCreate('system.user.view', 'web');
        $teacher = $this->staff($this->unitA);
        $this->updateUser($teacher, ['position_uuid' => Position::where('code', 'TEACHER')->value('uuid')])->assertOk();
        $unlinked = $this->staff($this->unitA);

        $viewer = $this->staff($this->unitA);
        $viewer->givePermissionTo('view_users');

        $rows = collect($this->actingAs($viewer)->getJson('/api/reporting/personnel')->assertOk()->json('rows'));
        $this->assertSame('ครู', $rows->firstWhere('user_id', $teacher->id)['position_group']);
        $this->assertSame('ยังไม่ระบุ', $rows->firstWhere('user_id', $unlinked->id)['position_group']);

        $filtered = collect($this->actingAs($viewer)->getJson('/api/reporting/personnel?position_group='.PositionGroup::where('code', 'TEACHER')->value('uuid'))->assertOk()->json('rows'));
        $this->assertSame([$teacher->id], $filtered->pluck('user_id')->all());
    }

    // ---------------------------------------------------------- match command

    public function test_match_command_dry_run_changes_nothing_and_apply_links_exact_names_only(): void
    {
        $exact = $this->staff($this->unitA, 'ครูผู้สอน', now()->subMonth()->toDateString());
        $fuzzy = $this->staff($this->unitA, 'ครู จ้างสอน', now()->subMonth()->toDateString());

        $this->artisan('personnel:match-positions', ['--dry-run' => true])
            ->expectsOutputToContain('จะจับคู่ 1 คน')
            ->expectsOutputToContain('จับคู่ไม่ได้ 1 คน')
            ->assertExitCode(0);
        $this->assertSame(0, PersonnelAssignment::whereNotNull('position_id')->count());

        $this->artisan('personnel:match-positions', ['--by' => 'admin@test.local'])->assertExitCode(0);

        $this->assertSame(Position::where('code', 'TEACHER_INSTRUCTOR')->value('id'), (int) PersonnelAssignment::where('user_id', $exact->id)->whereNull('effective_to')->value('position_id'));
        $this->assertNull(PersonnelAssignment::where('user_id', $fuzzy->id)->whereNull('effective_to')->value('position_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'personnel.position_assigned', 'resource_id' => (string) $exact->id, 'actor_id' => $this->admin->id]);
    }

    public function test_match_command_requires_authorised_actor_to_apply(): void
    {
        $this->staff($this->unitA, 'ครูผู้สอน');
        User::factory()->create(['email' => 'clerk@test.local', 'is_active' => true]);

        $this->artisan('personnel:match-positions')->assertExitCode(1);
        $this->artisan('personnel:match-positions', ['--by' => 'clerk@test.local'])->assertExitCode(1);

        $this->assertSame(0, PersonnelAssignment::whereNotNull('position_id')->count());
    }

    // ---------------------------------------------------------------- helpers

    private function orgUnit(string $code, ?int $parent): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent,
            'code' => $code,
            'name' => $code,
            'type' => $parent === null ? 'organization' : 'department',
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function staff(int $orgUnitId, ?string $position = null, ?string $since = null): User
    {
        $user = User::factory()->create(['org_unit_id' => $orgUnitId, 'department_id' => $this->department->id, 'is_active' => true]);
        $user->assignRole('Employee');
        $user->profile()->updateOrCreate(['user_id' => $user->id], ['first_name' => 'ทดสอบ', 'last_name' => Str::random(6)]);

        // ให้มี assignment ปัจจุบันหนึ่งแถวพร้อมข้อความตำแหน่งเดิม (เหมือนข้อมูลก่อนมีทะเบียนตำแหน่ง)
        $current = PersonnelAssignment::where('user_id', $user->id)->whereNull('effective_to')->first()
            ?? PersonnelAssignment::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'org_unit_id' => $orgUnitId, 'effective_from' => now()->toDateString()]);
        DB::table('personnel_assignments')->where('id', $current->id)->update([
            'position' => $position,
            'effective_from' => $since ?? now()->toDateString(),
        ]);

        return $user->fresh();
    }

    /**
     * เจ้าหน้าที่งานบุคลากรตาม Q4: บทบาท HR Head ซึ่งมี system.position.manage แต่ไม่มีสิทธิ์แก้ไขผู้ใช้
     */
    private function hrOfficer(): User
    {
        Permission::findOrCreate('system.position.manage', 'web');
        $role = Role::findOrCreate('HR Head', 'web');
        $role->givePermissionTo(['system.position.manage', 'view_users']);

        $hr = $this->staff($this->unitA);
        $hr->syncRoles(['HR Head']);

        return $hr->fresh();
    }

    private function updateUser(User $user, array $extra)
    {
        return $this->actingAs($this->admin)->putJson("/api/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
        ] + $extra);
    }

    private function leave(User $owner, LeaveType $type): Leave
    {
        return Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Pending',
        ]);
    }
}
