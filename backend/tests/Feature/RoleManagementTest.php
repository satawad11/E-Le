<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * หน้าจัดการบทบาทและสิทธิ์ — ภาษาไทยทั้งหน้า และรวมสิทธิ์ชื่อเดิมที่ซ้ำความหมาย (เจ้าของระบบขอ 18/10/2569)
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('system.menu.manage', 'web'));
    }

    public function test_every_permission_has_a_thai_label_without_english(): void
    {
        $groups = $this->actingAs($this->admin)->getJson('/api/admin/permissions')->assertOk()->json('groups');

        $this->assertNotEmpty($groups);
        foreach ($groups as $group) {
            $this->assertNotSame(PermissionCatalog::OTHER_GROUP, $group['name'], 'ทุกสิทธิ์ต้องมีคำอธิบายในแคตตาล็อก: '.implode(', ', array_column($group['permissions'], 'name')));
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $group['name']);
            foreach ($group['permissions'] as $permission) {
                $this->assertMatchesRegularExpression('/\p{Thai}/u', $permission['label'], $permission['name']);
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $permission['label'], "คำอธิบายของ {$permission['name']} ต้องไม่มีภาษาอังกฤษ");
            }
        }
        $this->assertSame(Permission::count(), collect($groups)->sum(fn ($g) => count($g['permissions'])), 'ต้องแสดงครบทุกสิทธิ์');
    }

    public function test_roles_are_listed_with_thai_names_and_safe_flags(): void
    {
        $employee = Role::findOrCreate('Employee', 'web');
        $employee->update(['description' => 'บุคลากร']);
        $custom = Role::create(['name' => 'custom-test', 'guard_name' => 'web', 'description' => 'เจ้าหน้าที่ทดสอบ']);
        $custom->givePermissionTo('system.menu.manage');

        $roles = collect($this->actingAs($this->admin)->getJson('/api/admin/roles')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('บุคลากร', $roles[$employee->id]['display_name']);
        $this->assertTrue($roles[$employee->id]['is_core']);
        $this->assertFalse($roles[$employee->id]['can_delete'], 'บทบาทหลักลบไม่ได้');
        $this->assertTrue($roles[$custom->id]['can_delete'], 'บทบาทที่ไม่มีผู้ใช้และไม่ใช่บทบาทหลักลบได้');
        $this->assertSame('จัดการเมนู บทบาท และชุดสิทธิ์', $roles[$custom->id]['permissions'][0]['label']);
        $this->assertSame('ระบบกลางและการบริหารระบบ', $roles[$custom->id]['permissions'][0]['module']);
    }

    public function test_creating_a_role_needs_only_a_thai_name_and_gets_an_internal_name(): void
    {
        $permission = Permission::findOrCreate('leave.report.view', 'web');

        $this->actingAs($this->admin)->postJson('/api/admin/roles', ['permission_ids' => []])
            ->assertUnprocessable()->assertJsonPath('errors.display_name.0', 'กรุณาระบุชื่อบทบาท');

        $id = $this->actingAs($this->admin)->postJson('/api/admin/roles', ['display_name' => 'เจ้าหน้าที่งานการเงิน', 'permission_ids' => [$permission->id]])
            ->assertCreated()->json('id');

        $role = Role::findOrFail($id);
        $this->assertSame('เจ้าหน้าที่งานการเงิน', $role->description);
        $this->assertStringStartsWith('custom-', $role->name);
        $this->assertTrue($role->hasPermissionTo('leave.report.view'));
    }

    public function test_editing_never_changes_the_internal_name(): void
    {
        $role = Role::findOrCreate('Director', 'web');

        $this->actingAs($this->admin)->putJson("/api/admin/roles/{$role->id}", ['name' => 'Hacked', 'display_name' => 'ผู้อำนวยการสถานศึกษา', 'permission_ids' => []])
            ->assertOk();

        $this->assertSame('Director', $role->fresh()->name, 'โค้ดอ้างชื่อนี้อยู่ ต้องไม่เปลี่ยน');
        $this->assertSame('ผู้อำนวยการสถานศึกษา', $role->fresh()->description);
    }

    public function test_core_roles_and_roles_in_use_cannot_be_deleted(): void
    {
        $core = Role::findOrCreate('HR Head', 'web');
        $used = Role::create(['name' => 'custom-used', 'guard_name' => 'web', 'description' => 'มีผู้ใช้']);
        User::factory()->create()->assignRole($used);
        $unused = Role::create(['name' => 'custom-unused', 'guard_name' => 'web', 'description' => 'ไม่มีผู้ใช้']);

        $this->actingAs($this->admin)->deleteJson("/api/admin/roles/{$core->id}")->assertUnprocessable()->assertJsonPath('message', 'บทบาทหลักของระบบลบไม่ได้');
        $this->actingAs($this->admin)->deleteJson("/api/admin/roles/{$used->id}")->assertUnprocessable();
        $this->actingAs($this->admin)->deleteJson("/api/admin/roles/{$unused->id}")->assertOk();

        $this->assertNull(Role::find($unused->id));
        $this->assertNotNull(Role::find($used->id));
    }

    public function test_role_management_requires_the_menu_permission(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/roles')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/roles', ['display_name' => 'แอบสร้าง', 'permission_ids' => []])->assertForbidden();
    }

    public function test_merging_legacy_names_moves_every_grant_to_the_canonical_name(): void
    {
        $migration = require database_path('migrations/2026_10_19_170000_merge_aliased_legacy_permissions.php');
        $legacy = Permission::create(['name' => 'edit_users', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'custom-legacy', 'guard_name' => 'web']);
        $role->givePermissionTo($legacy);
        $user = User::factory()->create();
        $user->givePermissionTo($legacy);
        DB::table('permission_aliases')->insert(['legacy_name' => 'edit_users', 'canonical_name' => 'system.user.manage', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_scopes')->insert(['model_type' => User::class, 'model_id' => $user->id, 'permission_name' => 'edit_users', 'scope' => 'ORGANIZATION', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNull(Permission::where('name', 'edit_users')->first(), 'ชื่อเดิมต้องถูกลบ');
        $this->assertTrue($role->fresh()->hasPermissionTo('system.user.manage'));
        $this->assertTrue($user->fresh()->hasDirectPermission('system.user.manage'));
        $this->assertFalse(DB::table('permission_aliases')->where('legacy_name', 'edit_users')->exists());
        $this->assertSame('system.user.manage', DB::table('permission_scopes')->where('model_id', $user->id)->value('permission_name'));
    }
}
