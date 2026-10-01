<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MenuService;
use App\Services\Modules\ModuleRegistry;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * INT-02 — โมดูลสารบรรณถูกลงทะเบียนกับระบบกลาง และมี permission namespace ของตัวเอง
 *
 * `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` §7 กำหนดให้สารบรรณเป็น "โมดูลหนึ่ง
 * ของระบบเดิม" ไม่ใช่ระบบที่สอง เทสต์นี้จึงยืนยันว่าโมดูลเสียบเข้ากับกลไกกลางที่มีอยู่แล้ว
 * ทุกตัว — ทะเบียนโมดูล, การเปิด-ปิด, ด่าน route, สารบัญสิทธิ์ และแถบเมนู —
 * โดยไม่ได้สร้างกลไกใหม่ขึ้นมาเลย
 *
 * ที่สำคัญไม่แพ้กันคือยืนยันว่า **การลงทะเบียนโมดูลไม่ได้แจกสิทธิ์ให้ใคร**
 * ชื่อสิทธิ์ 31 ชื่อถูกสร้างขึ้นเพื่อให้ route/เมนู/โค้ดอ้างถึงได้ในเช็คพอยต์ถัด ๆ ไป
 * แต่ ณ วันนี้ยังไม่มีผู้ใช้คนใดถือสิทธิ์เหล่านั้น — default DENY ตาม SoT §6
 */
class SarabanModuleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ModuleRegistry
    {
        return app(ModuleRegistry::class);
    }

    public function test_saraban_is_registered_as_a_module_of_the_existing_system(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->has('saraban'), 'โมดูลสารบรรณต้องอยู่ในทะเบียนโมดูลกลาง');

        $module = $registry->get('saraban');

        $this->assertSame('saraban', $module->code());
        $this->assertSame('saraban', $module->configKey);
        $this->assertSame('1.0.0', $module->version());
        $this->assertTrue($module->canDisable(), 'สารบรรณเป็นโมดูลธุรกิจ ต้องปิดได้');
        $this->assertSame(['system'], $module->dependencies());
    }

    /**
     * ศูนย์แจ้งเตือนต้องเป็นโครงสร้างพื้นฐานที่ "ไม่มีก็ยังทำงานได้"
     *
     * SoT §16 ห้ามให้ธุรกรรมทางธุรกิจล้มเพราะช่องทางแจ้งเตือนล้ม ถ้าประกาศว่า
     * สารบรรณพึ่งพาโมดูลแจ้งเตือน การปิดศูนย์แจ้งเตือนจะทำให้ลงทะเบียนรับหนังสือ
     * ไม่ได้ทั้งระบบ ซึ่งแรงเกินกว่าที่ข้อกำหนดตั้งใจ
     */
    public function test_turning_off_notifications_does_not_disable_saraban(): void
    {
        config(['modules.notification.enabled' => false]);

        $this->assertFalse($this->registry()->isEnabled('notification'));
        $this->assertTrue($this->registry()->isEnabled('saraban'));
    }

    public function test_saraban_can_be_switched_off_without_affecting_other_modules(): void
    {
        config(['modules.saraban.enabled' => false]);

        $this->assertFalse($this->registry()->isEnabled('saraban'));

        foreach (['system', 'leave', 'attendance', 'overtime', 'notification', 'reporting'] as $other) {
            $this->assertTrue(
                $this->registry()->isEnabled($other),
                "การปิดสารบรรณต้องไม่กระทบโมดูล {$other}"
            );
        }
    }

    /**
     * ด่าน route ของสารบรรณต้องไม่ไปคาบเกี่ยวกับของโมดูลอื่น
     *
     * `ModuleRegistry::moduleForPath()` เลือก pattern ที่ยาวที่สุดที่ตรงกัน
     * ถ้า `api/saraban*` ไปจับ path ของโมดูลเดิม การปิดสารบรรณจะปิดของคนอื่นไปด้วย
     */
    public function test_saraban_route_patterns_do_not_collide_with_existing_modules(): void
    {
        $registry = $this->registry();

        foreach (['api/leaves', 'api/approvals', 'api/attendances/me', 'api/overtime/orders', 'api/notifications', 'api/reporting/catalog'] as $path) {
            $owner = $registry->moduleForPath($path);

            $this->assertNotSame(
                'saraban',
                $owner?->code(),
                "เส้นทาง {$path} ต้องไม่ตกเป็นของโมดูลสารบรรณ"
            );
        }

        $this->assertSame('saraban', $registry->moduleForPath('api/saraban/documents')?->code());
    }

    public function test_the_permission_namespace_covers_every_capability_group_the_master_requires(): void
    {
        $names = array_keys($this->registry()->get('saraban')->permissions());

        /*
         * 13 กลุ่มตาม SoT §6 — `saraban.audit.*` และ `saraban.config.*` เพิ่มเข้ามา
         * เพราะ §25 (ประวัติการดำเนินการ) และ §3 (ตั้งค่าทะเบียนรายหน่วยงาน) ต้องใช้
         * ข้อกำหนดเขียนว่า "อย่างน้อย" จึงเพิ่มได้แต่ขาดไม่ได้
         */
        $requiredGroups = [
            'document', 'incoming', 'number', 'workflow', 'endorse', 'signature',
            'distribution', 'report', 'policy', 'authority',
            'audit', 'config',
        ];

        foreach ($requiredGroups as $group) {
            $this->assertNotEmpty(
                array_filter($names, fn (string $name) => str_starts_with($name, "saraban.{$group}.")),
                "permission namespace ขาดกลุ่ม saraban.{$group}.*"
            );
        }
    }

    public function test_every_saraban_permission_follows_the_module_resource_action_convention(): void
    {
        $offenders = array_values(array_filter(
            array_keys($this->registry()->get('saraban')->permissions()),
            fn (string $name) => preg_match('/^saraban\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name) !== 1,
        ));

        $this->assertSame([], $offenders, 'ชื่อสิทธิ์ผิดรูป module.resource.action: '.implode(', ', $offenders));
    }

    /**
     * ระบบที่ติดตั้งไปแล้วต้องได้ชื่อสิทธิ์ชุดใหม่จาก migration ไม่ใช่จาก seeder
     *
     * migration รันได้ครั้งเดียว — `2026_08_28_100000_seed_permission_catalog.php`
     * เรียกสารบัญไปแล้วรอบหนึ่ง ชื่อที่เพิ่มเข้าสารบัญหลังจากนั้นจึงไปไม่ถึงระบบจริง
     * ถ้าไม่มี migration ตัวใหม่ เทสต์นี้ไม่ได้ seed อะไรเลย จึงพิสูจน์ว่าชื่อมาจาก
     * migration จริง ๆ ไม่ใช่มาจากการ seed ในเทสต์
     */
    public function test_an_already_installed_system_receives_the_namespace_through_a_migration(): void
    {
        $names = array_keys($this->registry()->get('saraban')->permissions());

        $existing = Permission::query()
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        sort($names);
        sort($existing);

        $this->assertSame($names, $existing, 'ชื่อสิทธิ์สารบรรณต้องถูกสร้างโดย migration ตอนอัปเกรดระบบเดิม');

        $this->assertDatabaseCount('role_has_permissions', 0);
    }

    public function test_installing_creates_every_saraban_permission_but_grants_them_to_nobody(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        $names = array_keys($this->registry()->get('saraban')->permissions());

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'guard_name' => 'web']);
        }

        $granted = Permission::query()
            ->where('name', 'like', 'saraban.%')
            ->whereHas('roles')
            ->pluck('name')
            ->all();

        $this->assertSame([], $granted, 'การลงทะเบียนโมดูลต้องไม่แจกสิทธิ์สารบรรณให้บทบาทใดเลย');
    }

    /**
     * ผู้ใช้ทั่วไปต้องไม่ได้สิทธิ์สารบรรณติดมือมาจากที่ใด — default DENY
     */
    public function test_an_ordinary_user_holds_no_saraban_permission(): void
    {
        $this->seed(PermissionCatalogSeeder::class);

        $user = User::factory()->create();

        foreach (array_keys($this->registry()->get('saraban')->permissions()) as $name) {
            $this->assertFalse($user->can($name), "ผู้ใช้ทั่วไปต้องไม่มีสิทธิ์ {$name}");
        }
    }

    /**
     * เมนูสารบรรณมาพร้อมหน้าจอจริงแล้ว (INT-05)
     *
     * INT-02 ล็อกไว้ว่า "ต้องยังไม่มีเมนู" เพื่อบังคับว่าเมนูต้องมาพร้อมหน้าจอ
     * ไม่ใช่มาก่อนแล้วกดไป 404 — ตอนนี้หน้าจอมีจริงแล้ว ข้อบังคับจึงเปลี่ยนเป็น
     * "ทุกเมนูต้องผูกสิทธิ์ไว้" แทน
     *
     * ความถูกต้องของ path เทียบกับ `frontend/src/router/index.js` ถูกตรวจโดย
     * `MenuIntegrityTest` อยู่แล้ว — ไม่ตรวจซ้ำที่นี่
     */
    public function test_every_saraban_menu_is_gated_by_a_saraban_permission(): void
    {
        $sarabanMenus = array_values(array_filter(
            MenuSeeder::TREE,
            fn (array $item) => ($item['module'] ?? null) === 'saraban',
        ));

        $this->assertNotEmpty($sarabanMenus, 'สารบรรณต้องมีเมนูแล้วในเช็คพอยต์นี้');

        $catalogue = array_keys($this->registry()->get('saraban')->permissions());

        foreach ($sarabanMenus as $group) {
            foreach (array_merge([$group], $group['children'] ?? []) as $entry) {
                $permissions = $entry['permissions'] ?? [];

                $this->assertNotEmpty($permissions, "เมนู {$entry['code']} ต้องผูกสิทธิ์ไว้ ไม่ใช่เปิดให้ทุกคน");

                foreach ($permissions as $permission) {
                    $this->assertContains($permission, $catalogue, "เมนู {$entry['code']} อ้างสิทธิ์นอก namespace ของสารบรรณ");
                }
            }
        }
    }

    /** ผู้ใช้ที่ไม่มีสิทธิ์สารบรรณต้องไม่เห็นเมนูสารบรรณเลย */
    public function test_a_user_without_saraban_permissions_does_not_see_the_menu(): void
    {
        $this->seed(MenuSeeder::class);
        Cache::flush();

        $modules = array_column(app(MenuService::class)->getUserMenuTree(User::factory()->create()), 'module');

        $this->assertNotContains('saraban', $modules);
    }

    /**
     * โมดูลต้องแสดงในหน้า "โมดูลระบบ" ของระบบเดิม โดยใช้หน้าจอเดิมทั้งหมด
     *
     * หน้านั้นอ่านฟิลด์ `name_th`/`name`, `description`, `enabled`, `can_disable`,
     * `dependencies`, `config_key` — ถ้า manifest ขาดตัวใด การ์ดจะแสดงไม่ครบ
     */
    public function test_saraban_appears_in_the_existing_module_administration_screen(): void
    {
        Permission::findOrCreate('system.setting.manage', 'web');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $modules = $this->actingAs($admin)->getJson('/api/admin/modules')->assertOk()->json('modules');

        $saraban = collect($modules)->firstWhere('config_key', 'saraban');

        $this->assertNotNull($saraban, 'โมดูลสารบรรณต้องปรากฏในหน้าจัดการโมดูลเดิม');

        foreach (['code', 'name_th', 'description', 'enabled', 'can_disable', 'dependencies', 'config_key'] as $field) {
            $this->assertArrayHasKey($field, $saraban, "หน้าจัดการโมดูลต้องได้ฟิลด์ {$field}");
        }

        $this->assertTrue($saraban['can_disable']);
        $this->assertSame(['system'], $saraban['dependencies']);
        $this->assertNotSame('', trim((string) $saraban['description']));
    }

    /**
     * ปิดโมดูลแล้วต้องไม่มีเมนูของสารบรรณโผล่ในแถบเมนูของใครเลย
     *
     * ตอนนี้ยังไม่มีเมนู เทสต์นี้จึงเป็นด่านล่วงหน้าสำหรับเช็คพอยต์ที่เพิ่มเมนูจริง
     */
    public function test_no_saraban_menu_reaches_the_sidebar_while_the_module_is_off(): void
    {
        $this->seed(MenuSeeder::class);
        config(['modules.saraban.enabled' => false]);
        Cache::flush();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $modules = array_column(app(MenuService::class)->getUserMenuTree($admin), 'module');

        $this->assertNotContains('saraban', $modules);
    }
}
