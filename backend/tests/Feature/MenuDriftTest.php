<?php

namespace Tests\Feature;

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * กันเมนูใน `TREE` เดินหนีจากเมนูของระบบที่ติดตั้งไปแล้ว
 *
 * **ปัญหาจริงที่เทสต์นี้เกิดมาเพื่อกัน (พบ 2026-09-05)**
 *
 * เช็คพอยต์ INT-13 ถึง INT-20 เพิ่มเมนูสารบรรณเข้า `MenuSeeder::TREE` รวม 7 รายการ
 * โดยไม่มี migration พาไปให้ระบบที่ติดตั้งแล้ว · ผลคือหน้าจอทั้งเจ็ดมีอยู่จริงครบ
 * ทั้ง route และ component **แต่ไม่มีทางเข้าถึงจากแถบเมนู** อยู่หลายสัปดาห์
 *
 * **และเทสต์ทั้งชุดจับไม่ได้เลย** — `MenuIntegrityTest` เรียก `seed(MenuSeeder)`
 * ก่อนตรวจ จึงเทียบ `TREE` กับตัวมันเองเสมอ · แม้แต่การรัน migration ทั้งชุดบน
 * ฐานว่างก็จับไม่ได้ เพราะ migration ที่เรียก seeder จะใช้ `TREE` ฉบับล่าสุดอยู่ดี
 *
 * > ความต่างอยู่ที่ **ระบบที่ migration เดิมรันไปแล้ว** เท่านั้น — ซึ่งไม่มีฐานทดสอบใด
 * > จำลองได้โดยธรรมชาติ
 *
 * เทสต์นี้จึงเปลี่ยนวิธี: ตรึง **ลายนิ้วมือของ `TREE`** ไว้ · แก้ `TREE` เมื่อใด
 * เทสต์นี้จะล้มทันที และบังคับให้ผู้แก้ตอบคำถามว่า "ระบบที่ติดตั้งแล้วจะได้เมนูนี้
 * ได้อย่างไร" ก่อนจะผ่านไปได้
 */
class MenuDriftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ลายนิ้วมือของ `MenuSeeder::TREE` ณ migration ล่าสุดที่ sync เมนู
     *
     * **วิธีอัปเดตเมื่อเทสต์นี้ล้ม** — ทำสองอย่างพร้อมกันเสมอ ห้ามทำอย่างเดียว:
     *
     *   1. เพิ่ม migration ที่พาเมนูใหม่ไปให้ระบบที่ติดตั้งแล้ว
     *      (ดูตัวอย่าง `2026_09_09_100000_resync_saraban_menus_with_tree`)
     *   2. อัปเดตค่าด้านล่างเป็นค่าที่ข้อความความล้มเหลวแจ้ง
     *
     * การอัปเดตค่านี้เฉย ๆ โดยไม่ทำข้อ 1 = ปิดปากเทสต์ ไม่ใช่แก้ปัญหา
     */
    private const TREE_FINGERPRINT = 'fe943bcb7d9c8187b18a72d4c01a8fe18250692ef23b3165d30ea0091fa44045';

    /** migration ล่าสุดที่ทำหน้าที่ sync เมนูให้ระบบที่ติดตั้งแล้ว (ถอดกิ่งสารบรรณ) */
    private const LATEST_MENU_SYNC_MIGRATION = '2026_10_01_110000_drop_saraban_v12_module';

    /** migration ที่ส่งเมนูศูนย์ปฏิบัติการให้ระบบที่ติดตั้งแล้ว */
    private const OPERATIONS_MENU_MIGRATION = '2026_10_01_100000_move_operations_permissions_to_system';

    public function test_changing_the_menu_tree_requires_a_migration_for_installed_systems(): void
    {
        $fingerprint = hash('sha256', (string) json_encode(MenuSeeder::TREE));

        $this->assertSame(
            self::TREE_FINGERPRINT,
            $fingerprint,
            "`MenuSeeder::TREE` เปลี่ยนไปจากครั้งล่าสุดที่มี migration พาเมนูไปให้ระบบที่ติดตั้งแล้ว\n\n".
            "ระบบที่ติดตั้งไปแล้วจะ **ไม่ได้เมนูที่เพิ่มใหม่** เพราะ migration ที่รันไปแล้วไม่รันซ้ำ\n\n".
            "ให้เพิ่ม migration ที่ sync เมนู แล้วจึงอัปเดตค่านี้เป็น:\n  {$fingerprint}",
        );
    }

    /** migration ที่อ้างถึงข้างบนต้องมีอยู่จริง — ไม่ใช่ชื่อที่ค้างอยู่ในคอมเมนต์ */
    public function test_the_referenced_menu_sync_migration_exists(): void
    {
        $path = database_path('migrations/'.self::LATEST_MENU_SYNC_MIGRATION.'.php');

        $this->assertFileExists($path, 'migration ที่เทสต์นี้อ้างถึงหายไปแล้ว: '.self::LATEST_MENU_SYNC_MIGRATION);
    }

    /**
     * migration ที่อ้างถึงต้องพาเมนูไปให้ **ระบบที่ติดตั้งไปแล้ว** ได้จริง
     *
     * เทสต์ข้างบนตรวจแค่ว่าไฟล์มีอยู่ ซึ่งยังปิดปากตัวเองได้ด้วย migration เปล่า ๆ
     * จำลองระบบที่ติดตั้งก่อนมีเมนูศูนย์ปฏิบัติการ ด้วยการ seed แล้วลบสองเมนูนั้นออก
     * ก่อนเรียก `up()` ของ migration ตรง ๆ
     */
    public function test_the_latest_migration_removes_the_saraban_branch_from_installed_systems(): void
    {
        $this->seed(MenuSeeder::class);
        $parent = Menu::create(['code' => 'saraban', 'title_th' => 'งานสารบรรณ', 'title_en' => 'Saraban', 'module' => 'saraban', 'sort_order' => 9]);
        Menu::create(['code' => 'saraban.inbox', 'title_th' => 'กล่องหนังสือ', 'title_en' => 'Inbox', 'module' => 'saraban', 'parent_id' => $parent->id, 'sort_order' => 1]);

        (require database_path('migrations/'.self::LATEST_MENU_SYNC_MIGRATION.'.php'))->up();

        $this->assertSame(0, Menu::query()->where('module', 'saraban')->orWhere('code', 'like', 'saraban%')->count());
        $this->assertNotNull(Menu::where('code', 'settings.operations')->first(), 'เมนูของระบบต้องไม่ถูกลบตาม');
    }

    public function test_the_operations_migration_delivers_its_menus_to_installed_systems(): void
    {
        $this->seed(MenuSeeder::class);
        Menu::query()->whereIn('code', ['settings.operations', 'settings.compliance'])->delete();

        (require database_path('migrations/'.self::OPERATIONS_MENU_MIGRATION.'.php'))->up();

        $settings = Menu::where('code', 'settings')->firstOrFail();
        $operations = Menu::where('code', 'settings.operations')->firstOrFail();

        $this->assertSame($settings->id, $operations->parent_id);
        $this->assertSame('/admin/operations', $operations->route_path);
        $this->assertEqualsCanonicalizing(
            ['system.operations.view', 'system.exception.view'],
            $operations->permissions()->pluck('name')->all(),
        );
        $this->assertSame('/admin/operations/compliance', Menu::where('code', 'settings.compliance')->value('route_path'));
    }

    /**
     * **เมนูแม่ต้องครอบคลุมสิทธิ์ของลูกทุกตัว**
     *
     * `MenuService` กรองเมนูแม่ก่อนพิจารณาลูก — ถ้าแม่ประกาศสิทธิ์ไม่ครบ
     * ผู้ที่มีสิทธิ์เพียงข้อเดียวจะไม่เห็นกลุ่มนั้นเลย ทั้งที่มีสิทธิ์เข้าหน้าข้างใน
     *
     * เป็นความผิดพลาดที่มองไม่เห็นจากการอ่านโค้ด เพราะทุกอย่างดูถูกต้องหมด
     * จนกว่าจะมีคนที่ถือสิทธิ์ชุดนั้นพอดีมาเปิดใช้
     */
    public function test_every_group_covers_the_permissions_of_its_children(): void
    {
        foreach (MenuSeeder::TREE as $group) {
            $children = $group['children'] ?? [];

            if ($children === [] || ($group['permissions'] ?? []) === []) {
                continue;
            }

            $childPermissions = collect($children)
                ->flatMap(fn (array $child) => $child['permissions'] ?? [])
                ->unique();

            $uncovered = $childPermissions
                ->reject(fn (string $permission) => in_array($permission, $group['permissions'], true))
                ->values()
                ->all();

            $this->assertSame(
                [],
                $uncovered,
                "กลุ่มเมนู `{$group['code']}` ไม่ครอบคลุมสิทธิ์ของลูก — ผู้ที่ถือสิทธิ์เหล่านี้จะไม่เห็นกลุ่มเลย: ".
                implode(', ', $uncovered),
            );
        }
    }
}
