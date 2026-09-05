<?php

namespace Tests\Feature;

use Database\Seeders\MenuSeeder;
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
    private const TREE_FINGERPRINT = 'c5d2dcb17caff5e4eaebc40005e78019a7041a6f455ab5d81fc984b8566fa4af';

    /** migration ล่าสุดที่ทำหน้าที่ sync เมนูให้ระบบที่ติดตั้งแล้ว */
    private const LATEST_MENU_SYNC_MIGRATION = '2026_09_12_100000_split_saraban_menu_into_work_and_admin';

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
     * เมนูสารบรรณต้องครบทุกหน้าที่โมดูลมี — ตรวจจำนวนตรง ๆ
     *
     * ตัวเลขนี้คือสิ่งที่หายไปจริงในเหตุการณ์ข้างต้น (มี 6 จาก 13)
     *
     * นับรวมสองกลุ่ม เพราะเมนูสารบรรณถูกแยกเป็น "งานสารบรรณ" กับ
     * "การจัดการงานสารบรรณ" — สิ่งที่ต้องกันคือ **หน้าที่หายไปจากแถบเมนู**
     * ไม่ใช่ว่าหน้านั้นอยู่กลุ่มไหน
     */
    public function test_the_saraban_branch_declares_every_page_the_module_has(): void
    {
        $groups = collect(MenuSeeder::TREE)
            ->filter(fn (array $item) => ($item['module'] ?? null) === 'saraban');

        $this->assertCount(2, $groups, 'เมนูสารบรรณต้องมีสองกลุ่ม: งานประจำวัน และการจัดการ');

        $this->assertCount(
            14,
            $groups->flatMap(fn (array $item) => $item['children'] ?? [])->all(),
            'จำนวนหน้าของงานสารบรรณเปลี่ยนไป — ถ้าตั้งใจ ให้แก้ตัวเลขนี้พร้อม migration sync เมนู',
        );
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
