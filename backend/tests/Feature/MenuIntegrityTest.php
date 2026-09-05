<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CLEANUP-01 (D1) — เมนูต้องตรงกับหน้าจอที่มีอยู่จริง
 *
 * ตอนที่แถบเมนูเขียนตายตัวใน `App.vue` ตาราง `menus` เพี้ยนได้โดยไม่มีใครรู้
 * ตอนนี้แถบเมนูอ่านจากตารางนี้จริง ๆ ทุกแถวที่ผิดจะกลายเป็นเมนูที่กดแล้ว 404 ทันที
 */
class MenuIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * หน้าที่ตั้งใจให้ไม่มีเมนูชี้ถึง พร้อมเหตุผล
     *
     * @var array<string,string>
     */
    private const ROUTES_WITHOUT_MENU = [
        '/profile' => 'เข้าจากการ์ดผู้ใช้บนแถบเมนู',
        '/profile/access-history' => 'เข้าจากหน้าโปรไฟล์',
        '/forbidden' => 'หน้าแจ้งสิทธิ์ไม่พอ',
        '/admin/document-center/reports' => 'เข้าจากศูนย์จัดการเอกสาร',
        '/admin/document-center/templates/:templateId' => 'เข้าจากศูนย์จัดการเอกสาร',
        '/admin/document-templates' => 'ยุบเข้าศูนย์จัดการเอกสารแล้ว คงเส้นทางไว้ให้ลิงก์เดิมใช้ได้',
        '/leave-services' => 'ยุบเข้ารายการใบลาของฉันแล้ว คงเส้นทางไว้ให้ลิงก์เดิมใช้ได้',
        // INT-05 — เปิดจากแถวในทะเบียนหนังสือ ไม่ใช่เมนู (เป็นหน้ารายฉบับ)
        '/saraban/documents/:id' => 'เข้าจากทะเบียนหนังสือ',
        // INT-10 — พื้นที่ประทับตราของหนังสือฉบับหนึ่ง เปิดจากหน้ารายละเอียดหนังสือ
        '/saraban/documents/:id/workspace' => 'เข้าจากหน้ารายละเอียดหนังสือ',
    ];

    public function test_seeded_menus_match_the_declared_tree(): void
    {
        $this->seed(MenuSeeder::class);

        $expected = [];
        foreach (MenuSeeder::TREE as $item) {
            $expected[] = $item['code'];
            foreach ($item['children'] ?? [] as $child) {
                $expected[] = $child['code'];
            }
        }

        sort($expected);
        $actual = Menu::query()->orderBy('code')->pluck('code')->all();
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /*
     * CLEANUP-02 — ไม่มี seeder ตัวใดเพิ่มเมนูนอก `MenuSeeder::TREE`
     *
     * `DocumentEngineSeeder` เคยเพิ่มเมนู `admin.document_center` เองอีกรายการหนึ่ง
     * ทั้งที่หน้าเดียวกันมีอยู่แล้วในโครงหลัก และเพราะกิ่งแม่ที่มันหาไม่มีอยู่แล้ว
     * เมนูซ้ำนั้นจึงไปโผล่เป็นกลุ่มเมนูระดับบนสุดบนระบบที่ติดตั้งใหม่ทุกครั้ง
     * — จับได้ต่อเมื่อรัน seeder ครบชุดเท่านั้น
     */
    public function test_a_full_install_produces_exactly_the_declared_menu_tree(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [];
        foreach (MenuSeeder::TREE as $item) {
            $expected[] = $item['code'];
            foreach ($item['children'] ?? [] as $child) {
                $expected[] = $child['code'];
            }
        }

        sort($expected);
        $actual = Menu::query()->pluck('code')->all();
        sort($actual);

        $this->assertSame($expected, $actual, 'มี seeder เพิ่มเมนูนอกโครงหลัก — เมนูต้องมีแหล่งความจริงเดียว');
    }

    public function test_seeding_removes_menus_that_point_at_pages_which_no_longer_exist(): void
    {
        Menu::create([
            'code' => 'legacy.saraban',
            'title_th' => 'ทะเบียนหนังสือ',
            'title_en' => 'Saraban',
            'module' => 'system',
            'route_path' => '/saraban/documents',
            'sort_order' => 99,
        ]);

        $this->seed(MenuSeeder::class);

        $this->assertDatabaseMissing('menus', ['code' => 'legacy.saraban']);
    }

    public function test_every_menu_permission_name_exists(): void
    {
        $this->seed(MenuSeeder::class);

        $referenced = collect(MenuSeeder::TREE)
            ->flatMap(fn (array $item) => array_merge(
                $item['permissions'] ?? [],
                collect($item['children'] ?? [])->flatMap(fn (array $child) => $child['permissions'] ?? [])->all(),
            ))
            ->unique();

        $existing = Permission::query()->whereIn('name', $referenced)->pluck('name');

        $this->assertSame([], $referenced->diff($existing)->values()->all());
    }

    public function test_menu_tree_hides_items_the_user_has_no_permission_for(): void
    {
        $this->seed(MenuSeeder::class);

        $employee = User::factory()->create();
        $employee->givePermissionTo(Permission::findOrCreate('attendance.checkin', 'web'));

        $codes = $this->menuCodesFor($employee);

        $this->assertContains('leave.request', $codes, 'ยื่นใบลาต้องเห็นได้ทุกคน');
        $this->assertContains('attendance.checkin', $codes);
        $this->assertNotContains('users.list', $codes, 'บุคลากรทั่วไปต้องไม่เห็นเมนูจัดการผู้ใช้');
        $this->assertNotContains('settings.general', $codes);
        $this->assertNotContains('leave.governance', $codes);
    }

    public function test_administrator_sees_the_management_menus(): void
    {
        $this->seed(MenuSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $codes = $this->menuCodesFor($admin);

        foreach (['users.list', 'users.menus', 'settings.general', 'reporting.center'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_a_group_disappears_when_its_module_is_disabled(): void
    {
        $this->seed(MenuSeeder::class);
        config(['modules.attendance.enabled' => false]);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $codes = $this->menuCodesFor($admin);

        $this->assertNotContains('attendance', $codes);
        $this->assertNotContains('attendance.checkin', $codes);
        $this->assertContains('leave', $codes);
    }

    /*
     * เทสต์สองข้อล่างเทียบกับ `frontend/src/router/index.js` ตรง ๆ
     * ในคอนเทนเนอร์พัฒนามีแต่โฟลเดอร์ backend ถูก mount จึงข้ามไป — CI ที่ checkout
     * ทั้ง repository เป็นตัวบังคับจริง
     */
    public function test_every_menu_path_exists_in_the_frontend_router(): void
    {
        $routes = $this->frontendRoutePaths();

        $missing = [];
        foreach (MenuSeeder::TREE as $item) {
            foreach (array_merge([$item], $item['children'] ?? []) as $entry) {
                $path = $entry['route_path'] ?? null;
                if ($path !== null && ! in_array($path, $routes, true)) {
                    $missing[] = "{$entry['code']} → {$path}";
                }
            }
        }

        $this->assertSame([], $missing, 'เมนูชี้ไปยังหน้าที่ไม่มีใน router: '.implode(', ', $missing));
    }

    public function test_every_page_that_needs_sign_in_is_reachable_from_the_menu(): void
    {
        $routes = $this->frontendRoutePaths(authenticatedOnly: true);

        $menuPaths = [];
        foreach (MenuSeeder::TREE as $item) {
            foreach (array_merge([$item], $item['children'] ?? []) as $entry) {
                if (isset($entry['route_path'])) {
                    $menuPaths[] = $entry['route_path'];
                }
            }
        }

        $unreachable = array_values(array_diff($routes, $menuPaths, array_keys(self::ROUTES_WITHOUT_MENU)));

        $this->assertSame(
            [],
            $unreachable,
            'มีหน้าที่ต้องล็อกอินแต่ไม่มีเมนูชี้ถึงและไม่ได้ระบุเหตุผลไว้: '.implode(', ', $unreachable)
        );
    }

    /** @return array<int,string> */
    private function menuCodesFor(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/user/menus')->assertOk();

        $codes = [];
        foreach ($response->json('data') as $item) {
            $codes[] = $item['code'];
            foreach ($item['children'] ?? [] as $child) {
                $codes[] = $child['code'];
            }
        }

        return $codes;
    }

    /** @return array<int,string> */
    private function frontendRoutePaths(bool $authenticatedOnly = false): array
    {
        $file = base_path('../frontend/src/router/index.js');

        if (! is_file($file)) {
            $this->markTestSkipped('ไม่พบ frontend/src/router/index.js จากบริบทนี้ (CI เป็นตัวบังคับ)');
        }

        $source = file_get_contents($file);

        // แต่ละ route คือบล็อกที่ขึ้นต้นด้วย `path:` จนถึง `path:` ถัดไป
        $blocks = preg_split('/(?=\bpath:\s*)/', $source);
        $paths = [];

        foreach ($blocks as $block) {
            if (preg_match("/^path:\s*'([^']+)'/", $block, $match) !== 1) {
                continue;
            }
            $path = $match[1];
            if (str_contains($path, 'pathMatch')) {
                continue;
            }
            if (str_contains($block, 'redirect:')) {
                continue;
            }
            if ($authenticatedOnly && ! str_contains($block, 'requiresAuth: true')) {
                continue;
            }
            $paths[] = $path;
        }

        return $paths;
    }
}
