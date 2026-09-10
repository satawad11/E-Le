<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-026 — บทบาทระบบต้องถูกระบุด้วยคีย์ ไม่ใช่ชื่อที่แสดง
 *
 * ก่อนแก้: `RoleController` กันบทบาทหลักด้วยรายชื่อ `['SuperAdmin', ...]`
 * แต่บทบาทจริงชื่อ `Super Admin` (มีเว้นวรรค) ด่านจึงไม่เคยทำงาน — ผู้ดูแลเปลี่ยนชื่อ
 * หรือลบบทบาทผู้ดูแลระบบสูงสุดทิ้งได้ และไม่มีใครแก้กลับได้อีก
 */
class SystemRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminAccount(string $email = 'root@example.test'): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->assignRole($this->superAdminRole()->name);

        return $user->fresh();
    }

    private function superAdminRole(): Role
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        SystemRoles::backfillExistingRoles();

        return Role::findOrFail($role->id);
    }

    public function test_the_canonical_super_admin_role_is_found_by_key_not_by_display_name(): void
    {
        $this->superAdminRole();

        $found = Role::superAdmin();

        $this->assertNotNull($found);
        $this->assertSame(SystemRoles::SUPER_ADMIN, $found->system_key);
        $this->assertTrue($found->isSystemRole());
        $this->assertTrue($found->isSuperAdmin());
    }

    /** ชื่อเดิมอีกรุ่นหนึ่ง (`SuperAdmin` ไม่มีเว้นวรรค) ต้องได้คีย์เดียวกัน */
    public function test_the_legacy_display_name_alias_maps_to_the_same_key(): void
    {
        Role::create(['name' => 'SuperAdmin', 'guard_name' => 'web']);
        SystemRoles::backfillExistingRoles();

        $this->assertSame(SystemRoles::SUPER_ADMIN, Role::superAdmin()?->system_key);
    }

    public function test_a_system_role_cannot_be_renamed(): void
    {
        $role = $this->superAdminRole();
        $admin = $this->superAdminAccount();

        $this->actingAs($admin)
            ->putJson("/api/admin/roles/{$role->id}", ['name' => 'บทบาทที่ถูกเปลี่ยนชื่อ'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('Super Admin', $role->fresh()->name);
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $role = $this->superAdminRole();
        $admin = $this->superAdminAccount();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertNotNull(Role::superAdmin());
    }

    /** บทบาทที่ผู้ดูแลสร้างเองยังเปลี่ยนชื่อและลบได้ตามปกติ และต้องมี audit */
    public function test_an_ordinary_role_can_still_be_renamed_and_deleted_with_an_audit_trail(): void
    {
        $this->superAdminRole();
        $admin = $this->superAdminAccount();
        $role = Role::create(['name' => 'บทบาทชั่วคราว', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->putJson("/api/admin/roles/{$role->id}", ['name' => 'บทบาทชั่วคราว (แก้ชื่อ)'])
            ->assertOk();

        $this->assertSame('บทบาทชั่วคราว (แก้ชื่อ)', $role->fresh()->name);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertOk();

        $this->assertNull(Role::find($role->id));
        $this->assertTrue(AuditLog::where('action', 'role.updated')->exists());
        $this->assertTrue(AuditLog::where('action', 'role.deleted')->exists());
    }

    /** ถอดบทบาทผู้ดูแลระบบสูงสุดออกจากคนสุดท้ายไม่ได้ (self-lockout) */
    public function test_the_last_super_admin_cannot_demote_themselves(): void
    {
        $this->superAdminRole();
        $admin = $this->superAdminAccount();
        $employee = Role::create(['name' => 'Employee', 'guard_name' => 'web']);
        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ'])->id;

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'role_id' => $employee->id,
                'org_unit_id' => $department,
            ])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->hasRole('Super Admin'));
    }

    /** เมื่อมีผู้ดูแลอีกคนที่ยังใช้งานอยู่ การเปลี่ยนบทบาทของตนเองทำได้ */
    public function test_demoting_oneself_is_allowed_when_another_super_admin_remains(): void
    {
        $this->superAdminRole();
        $admin = $this->superAdminAccount();
        $this->superAdminAccount('second-root@example.test');
        $employee = Role::create(['name' => 'Employee', 'guard_name' => 'web']);
        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบ'])->id;

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'role_id' => $employee->id,
                'org_unit_id' => $department,
            ])
            ->assertOk();

        $this->assertFalse($admin->fresh()->hasRole('Super Admin'));
    }

    public function test_the_last_super_admin_account_cannot_be_deleted_or_suspended(): void
    {
        $this->superAdminRole();
        $admin = $this->superAdminAccount();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$admin->id}/toggle-status")
            ->assertStatus(422);

        $this->assertNotNull(User::find($admin->id));
        $this->assertTrue($admin->fresh()->is_active);
    }
}
