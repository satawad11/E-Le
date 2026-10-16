<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LEGACY-04 (ปิดงาน) — กันทางลัดยกระดับสิทธิ์ที่พบตอนเติม permission ชุดใหม่
 *
 * ข้อกำหนดด้านความปลอดภัยของงานนี้ระบุไว้ตรง ๆ ว่าการเติม permission ต้องไม่
 * ทำให้ผู้ใช้เห็นข้อมูลเพิ่มเกินหน้าที่ โดยเฉพาะ "การตั้งค่าระบบ" และ
 * "การจัดการ permission"
 *
 * ตอนตรวจ route พบว่าสองกลุ่มนี้ยอมรับ `edit_users` ด้วย ซึ่งแปลว่า HR
 * ที่แก้ไขข้อมูลผู้ใช้ได้ **มอบ permission ใดก็ได้ให้ตัวเอง** ผ่านหน้าจัดการสิทธิ์
 * หรือสร้าง role ใหม่แล้วผูก permission เข้าไปก็ได้ เป็นช่องที่มีมาก่อนเฟสนี้
 *
 * เทสต์ชุดนี้ล็อกไว้ว่าช่องนั้นปิดแล้วและต้องไม่ถูกเปิดกลับมาโดยไม่ตั้งใจ
 */
class PrivilegeEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * ผู้ที่ถือ `edit_users` คือผู้ที่แก้ไขข้อมูลผู้ใช้ได้ตามหน้าที่
     * ซึ่งเป็นคนละเรื่องกับการ "มอบสิทธิ์" ให้ใคร
     */
    private function userWhoCanEditUsers(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('edit_users');

        return $user->fresh();
    }

    public function test_editing_users_does_not_let_you_hand_out_permissions(): void
    {
        $editor = $this->userWhoCanEditUsers();
        $target = User::factory()->create();

        // หน้าที่เดิมยังทำได้ — ผ่านด่านสิทธิ์แล้วไปตกที่ validation ของฟอร์ม
        // (422 ไม่ใช่ 403 คือหลักฐานว่า authorization ยังอนุญาตอยู่)
        $this->assertTrue($editor->can('edit_users'));
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$target->id}", ['name' => 'ชื่อใหม่'])
            ->assertStatus(422);

        // แต่มอบสิทธิ์ให้ใครไม่ได้
        $this->actingAs($editor)->getJson('/api/admin/permissions/modules')->assertForbidden();
        $this->actingAs($editor)->getJson("/api/admin/users/{$target->id}/permissions")->assertForbidden();
        $this->actingAs($editor)->putJson("/api/admin/users/{$target->id}/permissions", [
            'permissions' => ['manage_settings'],
        ])->assertForbidden();

        $this->assertFalse($target->fresh()->can('manage_settings'), 'ต้องไม่มีสิทธิ์ใดหลุดไปถึงเป้าหมาย');
    }

    public function test_editing_users_does_not_let_you_create_roles(): void
    {
        $editor = $this->userWhoCanEditUsers();

        $this->actingAs($editor)->getJson('/api/admin/menus')->assertForbidden();
        $this->actingAs($editor)->getJson('/api/admin/roles')->assertForbidden();
        $this->actingAs($editor)->postJson('/api/admin/roles', ['name' => 'ผู้ดูแลจำแลง'])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'ผู้ดูแลจำแลง']);
    }

    /**
     * HR ยังทำงานเดิมได้ครบ — การปิดช่องข้างต้นต้องไม่ไปกระทบหน้าที่ประจำ
     */
    public function test_hr_keeps_the_duties_it_actually_has(): void
    {
        $hr = $this->userWithRole('HR Head');

        $this->assertTrue($hr->can('view_users'));
        $this->assertTrue($hr->can('attendance.view_all'));
        $this->assertTrue($hr->can('export_reports'));

        $this->actingAs($hr)->getJson('/api/admin/users')->assertOk();
    }

    public function test_the_administrator_keeps_both_capabilities(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $target = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/permissions/modules')->assertOk();
        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}/permissions")->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/roles')->assertOk();
    }

    /**
     * `saraban.setting.manage` ต้องเปิดได้แค่ค่าตั้งค่าของงานสารบรรณ
     *
     * เดิมมันอยู่ในกลุ่ม route เดียวกับ `/api/admin/settings` ทั้งก้อน ซึ่งรวม
     * ค่า SMTP, โทเคน Telegram/LINE, การล้างค่าลับ, ปีงบประมาณ และเทมเพลตเอกสาร
     */
    public function test_saraban_setting_manager_cannot_reach_system_settings(): void
    {
        $officer = $this->userWithRole('Document Officer');
        $officer->givePermissionTo('saraban.setting.manage');
        $officer = $officer->fresh();

        // เข้าถึงค่าของงานสารบรรณได้
        $this->actingAs($officer)->getJson('/api/saraban/settings')->assertOk();
        $this->actingAs($officer)->postJson('/api/saraban/settings', [
            'require_pin_for_signature' => '1',
        ])->assertOk();

        // แต่แตะค่าระดับระบบไม่ได้เลย
        $this->actingAs($officer)->getJson('/api/admin/settings')->assertForbidden();
        $this->actingAs($officer)->postJson('/api/admin/settings', ['institution_name' => 'x'])->assertForbidden();
        $this->actingAs($officer)->getJson('/api/admin/fiscal-years')->assertForbidden();
        $this->actingAs($officer)->getJson('/api/admin/document-templates')->assertForbidden();
        $this->actingAs($officer)->deleteJson('/api/admin/settings/secrets/telegram_bot_token')->assertForbidden();
    }

    /**
     * และต้องแก้ค่าที่ไม่ใช่ของงานสารบรรณผ่านทางของตัวเองไม่ได้ด้วย
     */
    public function test_saraban_settings_endpoint_rejects_keys_outside_its_scope(): void
    {
        $officer = $this->userWithRole('Document Officer');
        $officer->givePermissionTo('saraban.setting.manage');

        $this->actingAs($officer->fresh())
            ->postJson('/api/saraban/settings', ['mail_host' => 'evil.example.com'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('settings', ['key' => 'mail_host']);
    }

    public function test_document_officer_without_the_assignment_cannot_touch_saraban_settings(): void
    {
        $officer = $this->userWithRole('Document Officer');

        $this->actingAs($officer)->getJson('/api/saraban/settings')->assertForbidden();
        $this->actingAs($officer)->postJson('/api/saraban/settings', [
            'require_pin_for_signature' => '1',
        ])->assertForbidden();
    }

    /*
     * K-ORG-01 — ฟอร์มสร้าง/แก้ไข/นำเข้าผู้ใช้ต้องไม่เป็นทางลัดมอบบทบาทหรือสิทธิ์
     *
     * ผู้แก้ไขในชุดนี้คือ HR Head ที่ได้ `edit_users` เพิ่ม (สถานการณ์ที่จะเปิดให้ HR แก้ไขบัญชีผู้ใช้)
     * โดยไม่มี `admin.users.manage_permissions`
     */
    private function hrEditor(): User
    {
        $user = $this->userWithRole('HR Head');
        $user->givePermissionTo('edit_users');

        return $user->fresh();
    }

    private function userPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'department_id' => $user->department_id ?? Department::create(['name' => 'ฝ่ายทดสอบ'])->id,
        ], $overrides);
    }

    private function superAdminRoleId(): int
    {
        return Role::findByName('Super Admin', 'web')->id;
    }

    public function test_user_editor_cannot_make_themselves_super_admin(): void
    {
        $editor = $this->hrEditor();

        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$editor->id}", $this->userPayload($editor, ['role_id' => $this->superAdminRoleId()]))
            ->assertForbidden()
            ->assertJsonPath('message', 'ท่านไม่มีสิทธิ์เปลี่ยนบทบาทของผู้ใช้ (ต้องมีสิทธิ์ "กำหนดสิทธิ์รายบุคคล")');

        $this->assertFalse($editor->fresh()->hasRole('Super Admin'));
        $this->assertTrue($editor->fresh()->hasRole('HR Head'));
    }

    public function test_user_editor_cannot_grant_super_admin_to_someone_else(): void
    {
        $editor = $this->hrEditor();
        $target = $this->userWithRole('Employee');

        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$target->id}", $this->userPayload($target, ['role_id' => $this->superAdminRoleId()]))
            ->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole('Super Admin'));
    }

    public function test_user_editor_cannot_create_a_super_admin(): void
    {
        $editor = $this->hrEditor();

        $this->actingAs($editor)->postJson('/api/admin/users', [
            'name' => 'ผู้ดูแลจำแลง',
            'email' => 'fake-admin@example.com',
            'password' => 'a-very-long-password',
            'role_id' => $this->superAdminRoleId(),
            'department_id' => Department::create(['name' => 'ฝ่ายทดสอบ'])->id,
        ])->assertForbidden()->assertJsonPath('message', 'ไม่สามารถกำหนดบทบาทที่มีสิทธิ์สูงกว่าบทบาทของท่านได้');

        $this->assertDatabaseMissing('users', ['email' => 'fake-admin@example.com']);
    }

    public function test_user_editor_cannot_create_a_user_with_a_role_above_their_own(): void
    {
        $editor = $this->hrEditor();
        $above = Role::create(['name' => 'บทบาทที่สูงกว่า', 'guard_name' => 'web']);
        $above->givePermissionTo('manage_settings');

        $this->actingAs($editor)->postJson('/api/admin/users', [
            'name' => 'ผู้ใช้ใหม่',
            'email' => 'above@example.com',
            'password' => 'a-very-long-password',
            'role_id' => $above->id,
            'department_id' => Department::create(['name' => 'ฝ่ายทดสอบ'])->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'above@example.com']);
    }

    public function test_user_editor_can_create_users_with_a_role_within_their_own(): void
    {
        $editor = $this->hrEditor();

        $this->actingAs($editor)->postJson('/api/admin/users', [
            'name' => 'บุคลากรใหม่',
            'email' => 'staff@example.com',
            'password' => 'a-very-long-password',
            'role_id' => Role::findByName('Employee', 'web')->id,
            'department_id' => Department::create(['name' => 'ฝ่ายทดสอบ'])->id,
            // รายการว่างไม่ถือเป็นการมอบสิทธิ์
            'custom_permissions' => json_encode([]),
        ])->assertCreated();

        $this->assertTrue(User::where('email', 'staff@example.com')->firstOrFail()->hasRole('Employee'));
    }

    public function test_user_editor_cannot_add_individual_permissions(): void
    {
        $editor = $this->hrEditor();
        $target = $this->userWithRole('Employee');
        $department = Department::create(['name' => 'ฝ่ายทดสอบ']);

        // ให้ตนเอง
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$editor->id}", $this->userPayload($editor, [
                'department_id' => $department->id,
                'custom_permissions' => json_encode(['edit_users', 'admin.users.manage_permissions']),
            ]))
            ->assertForbidden()
            ->assertJsonPath('message', 'ท่านไม่มีสิทธิ์กำหนดสิทธิ์รายบุคคล (ต้องมีสิทธิ์ "กำหนดสิทธิ์รายบุคคล")');

        // ให้ผู้อื่น
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$target->id}", $this->userPayload($target, [
                'department_id' => $department->id,
                'custom_permissions' => json_encode(['manage_settings']),
            ]))
            ->assertForbidden();

        // ตอนสร้างผู้ใช้ใหม่
        $this->actingAs($editor)->postJson('/api/admin/users', [
            'name' => 'ผู้ใช้ใหม่',
            'email' => 'with-perms@example.com',
            'password' => 'a-very-long-password',
            'role_id' => Role::findByName('Employee', 'web')->id,
            'department_id' => $department->id,
            'custom_permissions' => json_encode(['manage_settings']),
        ])->assertForbidden();

        $this->assertFalse($editor->fresh()->can('admin.users.manage_permissions'));
        $this->assertFalse($target->fresh()->hasDirectPermission('manage_settings'));
        $this->assertDatabaseMissing('users', ['email' => 'with-perms@example.com']);
    }

    public function test_user_editor_cannot_remove_individual_permissions_either(): void
    {
        $editor = $this->hrEditor();
        $target = $this->userWithRole('Employee');
        $target->givePermissionTo('export_reports');

        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$target->id}", $this->userPayload($target, ['custom_permissions' => json_encode([])]))
            ->assertForbidden();

        $this->assertTrue($target->fresh()->hasDirectPermission('export_reports'));
    }

    public function test_user_editor_can_still_edit_names_and_profiles(): void
    {
        $editor = $this->hrEditor();
        $target = $this->userWithRole('Employee');
        $target->givePermissionTo('export_reports');

        // ค่าบทบาท/สิทธิ์เดิมที่ส่งกลับมา ต้องบันทึกผ่าน
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$target->id}", $this->userPayload($target, [
                'name' => 'ชื่อใหม่',
                'custom_permissions' => json_encode(['export_reports']),
                'profile' => ['first_name' => 'สมหญิง', 'position' => 'ครู'],
            ]))
            ->assertOk();

        // ไม่ส่ง custom_permissions (หน้าเว็บของผู้ไม่มีสิทธิ์กำหนดสิทธิ์) — สิทธิ์เดิมต้องคงอยู่ รวมบัญชีตนเอง
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$editor->id}", $this->userPayload($editor, ['name' => 'ชื่อของฉัน']))
            ->assertOk();

        $target = $target->fresh();
        $this->assertSame('สมหญิง', $target->profile->first_name);
        $this->assertSame('ครู', $target->profile->position);
        $this->assertTrue($target->hasRole('Employee'));
        $this->assertTrue($target->hasDirectPermission('export_reports'));
        $this->assertSame('ชื่อของฉัน', $editor->fresh()->name);
        $this->assertTrue($editor->fresh()->hasDirectPermission('edit_users'), 'แก้บัญชีตนเองต้องไม่ล้างสิทธิ์ที่มีอยู่');
    }

    public function test_user_editor_cannot_take_over_a_higher_account_through_its_credentials(): void
    {
        $editor = $this->hrEditor();
        $admin = $this->userWithRole('Super Admin');

        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$admin->id}", $this->userPayload($admin, ['password' => 'attacker-chosen-password']))
            ->assertForbidden()
            ->assertJsonPath('message', 'ไม่สามารถเปลี่ยนอีเมลหรือรหัสผ่านของผู้ใช้ที่มีสิทธิ์สูงกว่าท่านได้');
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$admin->id}", $this->userPayload($admin, ['email' => 'attacker@example.com']))
            ->assertForbidden();

        $this->assertNotSame('attacker@example.com', $admin->fresh()->email);

        // ข้อมูลทั่วไปของผู้ที่สูงกว่ายังแก้ได้ตามหน้าที่ HR
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$admin->id}", $this->userPayload($admin, ['name' => 'ชื่อที่แก้โดย HR']))
            ->assertOk();
        $this->assertSame('ชื่อที่แก้โดย HR', $admin->fresh()->name);

        // รหัสผ่านของผู้ที่ไม่สูงกว่ายังตั้งใหม่ได้
        $employee = $this->userWithRole('Employee');
        $this->actingAs($editor)
            ->putJson("/api/admin/users/{$employee->id}", $this->userPayload($employee, ['password' => 'a-new-long-password']))
            ->assertOk();
    }

    public function test_user_editor_cannot_import_super_admins(): void
    {
        $editor = $this->hrEditor();
        $department = Department::create(['name' => 'ฝ่ายทดสอบ', 'is_active' => true]);
        $employeeRoleId = Role::findByName('Employee', 'web')->id;
        $csv = "name,email,role_id,department_id\n"
            ."ผู้ดูแลจำแลง,fake-admin@example.com,{$this->superAdminRoleId()},{$department->id}\n"
            ."บุคลากรปกติ,staff@example.com,{$employeeRoleId},{$department->id}\n";

        $response = $this->actingAs($editor)
            ->post('/api/admin/users/import', ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertStringContainsString('บรรทัดที่ 2', $response->json('errors.0'));
        $this->assertStringContainsString('ไม่สามารถกำหนดบทบาทที่มีสิทธิ์สูงกว่าบทบาทของท่านได้', $response->json('errors.0'));
        $this->assertDatabaseMissing('users', ['email' => 'fake-admin@example.com']);
        $this->assertTrue(User::where('email', 'staff@example.com')->firstOrFail()->hasRole('Employee'));
    }

    public function test_assignable_roles_only_lists_roles_within_the_editor(): void
    {
        $editorRoles = $this->actingAs($this->hrEditor())->getJson('/api/admin/assignable-roles')
            ->assertOk()
            ->assertJsonPath('can_manage_access', false)
            ->json('roles.*.name');

        $this->assertContains('Employee', $editorRoles);
        $this->assertContains('HR Head', $editorRoles);
        $this->assertNotContains('Super Admin', $editorRoles);

        $adminRoles = $this->actingAs($this->userWithRole('Super Admin'))->getJson('/api/admin/assignable-roles')
            ->assertOk()
            ->assertJsonPath('can_manage_access', true)
            ->json('roles.*.name');
        $this->assertContains('Super Admin', $adminRoles);

        $this->actingAs($this->userWithRole('Employee'))->getJson('/api/admin/assignable-roles')->assertForbidden();
    }

    public function test_holder_of_manage_permissions_can_still_change_roles_and_permissions(): void
    {
        $manager = $this->hrEditor();
        $manager->givePermissionTo('admin.users.manage_permissions');
        $target = $this->userWithRole('Employee');

        $this->actingAs($manager->fresh())
            ->putJson("/api/admin/users/{$target->id}", $this->userPayload($target, [
                'role_id' => Role::findByName('Director', 'web')->id,
                'custom_permissions' => json_encode(['export_reports']),
            ]))
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('Director'));
        $this->assertTrue($target->fresh()->hasDirectPermission('export_reports'));
    }
}
