<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Services\Modules\ModuleStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CLEANUP-02 — การเปิด-ปิดโมดูลมีกลไกเดียวและมีผลจริง
 *
 * เดิมมีสองชุดที่ไม่คุยกัน: `config/modules.*.enabled` ที่ตั้งจาก env เท่านั้นและเป็น
 * ตัวที่เมนู, ด่าน route และ Notification Center ใช้จริง กับค่าตั้งค่า
 * `enable_leave_menu` / `enable_attendance_menu` ที่หน้า "โมดูลระบบ" เขียน
 * แต่ไม่มีอะไรอ่านอีกเลยหลังจากแถบเมนูเปลี่ยนไปอ่านจากตาราง `menus`
 * — สวิตช์ในหน้านั้นจึงกดแล้วไม่มีอะไรเกิดขึ้น
 */
class ModuleToggleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Permission::findOrCreate('system.setting.manage', 'web');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        return $admin;
    }

    public function test_the_module_list_reports_the_state_the_rest_of_the_system_uses(): void
    {
        $modules = $this->actingAs($this->admin())->getJson('/api/admin/modules')->assertOk()->json('modules');

        $leave = collect($modules)->firstWhere('config_key', 'leave');

        $this->assertNotNull($leave);
        $this->assertTrue($leave['enabled']);
        $this->assertTrue($leave['can_disable']);
    }

    public function test_turning_a_module_off_is_stored_as_a_setting_and_takes_effect(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/modules', ['module' => 'attendance', 'enabled' => false])
            ->assertOk();

        $this->assertDatabaseHas('settings', ['key' => 'module_attendance_enabled', 'value' => '0']);
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('attendance'));

        // ด่าน route ของโมดูลต้องปิดตามทันที ไม่ใช่แค่ซ่อนเมนู
        $this->actingAs($this->admin())->getJson('/api/attendances/me')->assertNotFound();
    }

    public function test_turning_a_module_off_also_disables_the_modules_that_depend_on_it(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/modules', ['module' => 'attendance', 'enabled' => false])
            ->assertOk();

        $this->assertContains('ระบบงานล่วงเวลา', $response->json('affected_dependants'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('overtime'));
    }

    public function test_the_core_module_cannot_be_switched_off(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/modules', ['module' => 'admin', 'enabled' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module');
    }

    public function test_an_unknown_module_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/modules', ['module' => 'saraban', 'enabled' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module');
    }

    public function test_a_stored_setting_overrides_the_environment_default_at_boot(): void
    {
        Setting::create(['key' => 'module_attendance_enabled', 'value' => '0']);

        /*
         * `AppServiceProvider` เรียกตัวนี้ตอน boot — เรียกตรง ๆ เพราะการ boot ใหม่
         * ในชุดทดสอบจะตัดการเชื่อมต่อฐานข้อมูลในหน่วยความจำทิ้งไปด้วย
         */
        app(ModuleStateResolver::class)->applyToConfig();

        $this->assertFalse(config('modules.attendance.enabled'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('attendance'));
    }

    public function test_switching_modules_requires_the_settings_permission(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson('/api/admin/modules')->assertForbidden();
        $this->actingAs($outsider)
            ->postJson('/api/admin/modules', ['module' => 'leave', 'enabled' => false])
            ->assertForbidden();
    }
}
