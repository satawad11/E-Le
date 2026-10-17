<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrgScopeResolver;
use App\Services\PermissionScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ORG-ACCESS-01 — ให้/ถอนขอบเขตของสิทธิ์รายคนผ่านคำสั่ง artisan พร้อม audit
 *
 * สิ่งที่ต้องพิสูจน์:
 *  1. รองฯ ที่เป็นหัวหน้าฝ่ายและได้ ORGANIZATION เห็นทั้งองค์กร (Q6) ถอนแล้วกลับเหลือฝ่ายตน
 *  2. ทุกการเปลี่ยนแปลงบันทึก audit พร้อมผู้สั่งและเหตุผล
 *  3. ผู้ไม่มีสิทธิ์จัดการสิทธิ์, การให้ตนเอง, ไม่มีเหตุผล, ข้อมูลผิด — ถูกปฏิเสธและไม่มีอะไรเปลี่ยน
 *  4. ชื่อสิทธิ์เก่าเก็บเป็นชื่อ canonical และมีแถวเดียวต่อคนต่อสิทธิ์
 */
class PermissionScopeCommandTest extends TestCase
{
    use RefreshDatabase;

    private OrgScopeResolver $resolver;

    private User $admin;

    private User $deputy;

    private int $root;

    private int $division;

    private int $dept;

    private int $otherDivision;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('leave.approval.dept', 'web');
        Permission::findOrCreate('attendance.view_all', 'web');
        Permission::findOrCreate('leave.report.view', 'web');
        Permission::findOrCreate('view_users', 'web');
        Permission::findOrCreate('system.user.view', 'web');
        Permission::findOrCreate(PermissionScopeService::MANAGE_ABILITY, 'web');

        $this->resolver = app(OrgScopeResolver::class);

        $this->root = $this->orgUnit('ROOT', 'วิทยาลัย', null);
        $this->division = $this->orgUnit('DIV-ACAD', 'ฝ่ายวิชาการ', $this->root);
        $this->dept = $this->orgUnit('DEPT-ELEC', 'แผนกวิชาช่างไฟฟ้า', $this->division);
        $this->otherDivision = $this->orgUnit('DIV-RES', 'ฝ่ายบริหารทรัพยากร', $this->root);

        $this->admin = User::factory()->create(['email' => 'admin@test.local', 'org_unit_id' => $this->otherDivision, 'is_active' => true]);
        $this->admin->givePermissionTo(PermissionScopeService::MANAGE_ABILITY);

        $this->deputy = User::factory()->create(['email' => 'deputy@test.local', 'org_unit_id' => $this->division, 'is_active' => true]);
        $this->deputy->givePermissionTo(['leave.approval.dept', 'attendance.view_all']);
        $this->manage($this->deputy, $this->division);
    }

    // ------------------------------------------------------- ให้ขอบเขต (Q6)

    public function test_deputy_granted_organization_sees_whole_organization_and_grant_is_audited(): void
    {
        $this->assertNotNull($this->resolver->visibleOrgUnitIds($this->deputy, 'leave.approval.dept', OrgScopeResolver::ORGANIZATION));

        $this->artisan('access:scope-grant', [
            'user' => 'deputy@test.local',
            'permission' => 'leave.approval.dept',
            'scope' => 'ORGANIZATION',
            '--by' => 'admin@test.local',
            '--reason' => 'คำสั่งวิทยาลัยที่ 123/2569',
        ])->assertExitCode(0);

        $this->assertNull($this->resolver->visibleOrgUnitIds($this->deputy, 'leave.approval.dept', OrgScopeResolver::ORGANIZATION));
        $this->assertTrue($this->resolver->canSeeOrgUnit($this->deputy, 'leave.approval.dept', $this->otherDivision));

        // สิทธิ์อื่นที่ไม่ได้ให้ ยังถูกจำกัดตามฝ่ายที่เป็นหัวหน้า
        $this->assertFalse($this->resolver->canSeeOrgUnit($this->deputy, 'attendance.view_all', $this->otherDivision, OrgScopeResolver::ORGANIZATION));

        $row = DB::table('permission_scopes')->where('model_id', $this->deputy->id)->first();
        $this->assertSame('ORGANIZATION', $row->scope);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.scope.granted',
            'resource_type' => 'permission_scope',
            'resource_id' => (string) $row->id,
            'actor_id' => $this->admin->id,
            'reason' => 'คำสั่งวิทยาลัยที่ 123/2569',
        ]);
    }

    public function test_user_can_be_found_by_uuid(): void
    {
        $this->artisan('access:scope-grant', [
            'user' => $this->deputy->fresh()->uuid ?? $this->assignUuid($this->deputy),
            'permission' => 'attendance.view_all',
            'scope' => 'ORGANIZATION',
            '--by' => 'admin@test.local',
            '--reason' => 'คำสั่งที่ 1/2569',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('permission_scopes', ['model_id' => $this->deputy->id, 'permission_name' => 'attendance.view_all']);
    }

    public function test_legacy_name_is_stored_as_canonical_and_regrant_replaces_existing_row(): void
    {
        DB::table('permission_scopes')->insert([
            'model_type' => $this->deputy->getMorphClass(),
            'model_id' => $this->deputy->id,
            'permission_name' => 'view_users',
            'scope' => 'UNIT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('access:scope-grant', [
            'user' => 'deputy@test.local',
            'permission' => 'view_users',
            'scope' => 'organization',
            '--by' => 'admin@test.local',
            '--reason' => 'คำสั่งที่ 2/2569',
        ])->assertExitCode(0);

        $rows = DB::table('permission_scopes')->where('model_id', $this->deputy->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('system.user.view', $rows->first()->permission_name);
        $this->assertSame('ORGANIZATION', $rows->first()->scope);

        $log = DB::table('audit_logs')->where('action', 'access.scope.granted')->first();
        $this->assertStringContainsString('view_users', (string) $log->before);
    }

    public function test_custom_scope_requires_unit_and_other_scopes_reject_unit(): void
    {
        $this->grant(['scope' => 'CUSTOM'])->assertExitCode(1);
        $this->grant(['scope' => 'ORGANIZATION', '--unit' => 'DEPT-ELEC'])->assertExitCode(1);
        $this->assertDatabaseCount('permission_scopes', 0);

        $this->grant(['scope' => 'CUSTOM', '--unit' => 'DIV-RES'])->assertExitCode(0);
        $this->assertTrue($this->resolver->canSeeOrgUnit($this->deputy, 'leave.approval.dept', $this->otherDivision));
        $this->assertFalse($this->resolver->canSeeOrgUnit($this->deputy, 'leave.approval.dept', $this->dept));
    }

    public function test_warns_when_user_does_not_hold_the_permission(): void
    {
        $this->grant(['permission' => 'leave.report.view'])
            ->expectsOutputToContain('ยังไม่มีสิทธิ์ leave.report.view')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------ การปฏิเสธ

    public function test_actor_without_manage_permission_is_refused(): void
    {
        User::factory()->create(['email' => 'clerk@test.local', 'is_active' => true]);

        $this->grant(['--by' => 'clerk@test.local'])->assertExitCode(1);

        $this->assertDatabaseCount('permission_scopes', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'access.scope.granted']);
    }

    public function test_inactive_actor_is_refused(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();

        $this->grant()->assertExitCode(1);
        $this->assertDatabaseCount('permission_scopes', 0);
    }

    public function test_actor_cannot_grant_scope_to_self(): void
    {
        $this->grant(['user' => 'admin@test.local'])->assertExitCode(1);
        $this->assertDatabaseCount('permission_scopes', 0);
    }

    public function test_reason_is_required(): void
    {
        $this->grant(['--reason' => '   '])->assertExitCode(1);
        $this->assertDatabaseCount('permission_scopes', 0);
    }

    public function test_unknown_permission_scope_or_user_is_refused(): void
    {
        $this->grant(['permission' => 'no.such.permission'])->assertExitCode(1);
        $this->grant(['scope' => 'EVERYTHING'])->assertExitCode(1);
        $this->grant(['user' => 'nobody@test.local'])->assertExitCode(1);
        $this->grant(['scope' => 'CUSTOM', '--unit' => 'NO-UNIT'])->assertExitCode(1);

        $this->assertDatabaseCount('permission_scopes', 0);
    }

    // -------------------------------------------------------------- ถอนขอบเขต

    public function test_revoke_returns_deputy_to_own_division_and_is_audited(): void
    {
        $this->grant()->assertExitCode(0);
        $rowId = DB::table('permission_scopes')->value('id');

        $this->artisan('access:scope-revoke', [
            'user' => 'deputy@test.local',
            'permission' => 'leave.approval.dept',
            '--by' => 'admin@test.local',
            '--reason' => 'พ้นตำแหน่งตามคำสั่งที่ 9/2569',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('permission_scopes', 0);
        $this->assertFalse($this->resolver->canSeeOrgUnit($this->deputy, 'leave.approval.dept', $this->otherDivision, OrgScopeResolver::ORGANIZATION));
        $this->assertTrue($this->resolver->canSeeOrgUnit($this->deputy, 'leave.approval.dept', $this->dept, OrgScopeResolver::ORGANIZATION));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.scope.revoked',
            'resource_id' => (string) $rowId,
            'actor_id' => $this->admin->id,
            'reason' => 'พ้นตำแหน่งตามคำสั่งที่ 9/2569',
        ]);
    }

    public function test_revoke_without_existing_scope_fails(): void
    {
        $this->artisan('access:scope-revoke', [
            'user' => 'deputy@test.local',
            'permission' => 'leave.approval.dept',
            '--by' => 'admin@test.local',
            '--reason' => 'ทดสอบ',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'access.scope.revoked']);
    }

    public function test_revoke_requires_authorised_actor(): void
    {
        $this->grant()->assertExitCode(0);
        User::factory()->create(['email' => 'clerk@test.local', 'is_active' => true]);

        $this->artisan('access:scope-revoke', [
            'user' => 'deputy@test.local',
            'permission' => 'leave.approval.dept',
            '--by' => 'clerk@test.local',
            '--reason' => 'ทดสอบ',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('permission_scopes', 1);
    }

    // ------------------------------------------------------------------ แสดงผล

    public function test_list_shows_granted_scopes(): void
    {
        $this->grant()->assertExitCode(0);

        $this->artisan('access:scope-list', ['user' => 'deputy@test.local'])
            ->expectsOutputToContain('leave.approval.dept')
            ->assertExitCode(0);

        $this->artisan('access:scope-list', ['user' => 'admin@test.local'])
            ->expectsOutputToContain('ไม่มีขอบเขตที่กำหนดไว้')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------------- helpers

    private function grant(array $overrides = [])
    {
        return $this->artisan('access:scope-grant', array_merge([
            'user' => 'deputy@test.local',
            'permission' => 'leave.approval.dept',
            'scope' => 'ORGANIZATION',
            '--by' => 'admin@test.local',
            '--reason' => 'คำสั่งวิทยาลัยที่ 123/2569',
        ], $overrides));
    }

    private function orgUnit(string $code, string $name, ?int $parent): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent,
            'code' => $code,
            'name' => $name,
            'type' => $parent === null ? 'organization' : 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function manage(User $user, int $orgUnitId): void
    {
        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $orgUnitId,
            'user_id' => $user->getKey(),
            'assignment_type' => 'MANAGER',
            'scope' => OrgScopeResolver::DESCENDANTS,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignUuid(User $user): string
    {
        $uuid = (string) Str::uuid();
        DB::table('users')->where('id', $user->id)->update(['uuid' => $uuid]);

        return $uuid;
    }
}
