<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
