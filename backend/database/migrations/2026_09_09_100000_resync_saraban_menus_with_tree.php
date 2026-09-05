<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * ทำให้เมนูงานสารบรรณในระบบที่ติดตั้งแล้ว ตรงกับ `MenuSeeder::TREE`
 *
 * **ปัญหาที่แก้ (พบจากการตรวจทาน 2026-09-05)**
 *
 * `2026_09_04_110000_add_saraban_menus` รันไปตอน INT-05 เมื่อ `TREE` ยังมีเมนู
 * สารบรรณ 6 รายการ · เช็คพอยต์ INT-13 ถึง INT-20 เพิ่มอีก 7 รายการเข้า `TREE`
 * **แต่ไม่มี migration ใดพาไปให้ระบบที่ติดตั้งแล้ว** — migration ที่รันไปแล้ว
 * ไม่รันซ้ำ · ผลคือหน้าจอทั้งเจ็ดหน้ามีอยู่จริงทั้ง route และ component
 * แต่ **ไม่มีทางเข้าถึงจากแถบเมนู**
 *
 * และเทสต์ทั้งชุดจับไม่ได้ เพราะรันบนฐานที่ seed ใหม่เสมอ ซึ่ง `TREE` ตรงอยู่แล้ว
 * (ปิดช่องนี้ด้วย `MenuDriftTest`)
 *
 * **ทำไมไม่เรียก `MenuSeeder::run()` ทั้งตัว**
 *
 * seeder ตัวนั้น (ก) ลบทุกแถวที่ไม่อยู่ใน `TREE` ซึ่งรวมเมนูที่ผู้ดูแลอาจสร้างเอง
 * ผ่านหน้าจัดการเมนู (`system.menu.manage`) และ (ข) เรียก `Cache::flush()`
 * ล้างแคชทั้งระบบ · ทั้งสองอย่างเกินความจำเป็นของงานนี้ และข้อแรกทำข้อมูลหาย
 *
 * migration นี้จึง **เพิ่มและปรับเฉพาะกิ่งสารบรรณ ไม่ลบอะไรเลย** และล้างเฉพาะ
 * แคชเมนูรายผู้ใช้
 */
return new class extends Migration
{
    public function up(): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', 'saraban');

        if ($branch === null) {
            return;
        }

        $parent = $this->upsert($branch, $this->sortOrderOf('saraban'), null);

        foreach ($branch['children'] ?? [] as $order => $child) {
            $child['module'] ??= $branch['module'];
            $this->upsert($child, $order + 1, $parent->id);
        }

        $this->forgetMenuCaches();
    }

    public function down(): void
    {
        /*
         * ไม่ย้อนกลับ — การลบเมนูคืนจะพาหน้าที่ใช้งานอยู่หายไปจากแถบเมนู
         * และ `TREE` ยังเป็นแหล่งความจริงเดิมอยู่ดี
         */
    }

    private function sortOrderOf(string $code): int
    {
        $index = collect(MenuSeeder::TREE)->search(fn (array $item) => $item['code'] === $code);

        return is_int($index) ? $index + 1 : 1;
    }

    /** @param array<string,mixed> $item */
    private function upsert(array $item, int $sortOrder, ?int $parentId): Menu
    {
        $menu = Menu::updateOrCreate(['code' => $item['code']], [
            'title_th' => $item['title_th'],
            'title_en' => $item['title_en'],
            'module' => $item['module'],
            'icon' => $item['icon'] ?? null,
            'route_name' => $item['route_name'] ?? null,
            'route_path' => $item['route_path'] ?? null,
            'component_path' => null,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'is_external' => false,
        ]);

        // สร้างชื่อสิทธิ์ที่ยังไม่มีเท่านั้น — ไม่ได้แจกสิทธิ์ให้ผู้ใช้คนใด
        $permissionIds = collect($item['permissions'] ?? [])
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
            ->all();

        $menu->permissions()->sync($permissionIds);

        return $menu;
    }

    /**
     * ล้าง **เฉพาะแคชเมนู** — คีย์คือ `user_menu_tree_{id}_{fingerprint}`
     *
     * fingerprint เปลี่ยนตามสถานะโมดูล จึงเดาคีย์เต็มไม่ได้ · ใช้วิธีล้างตาม
     * ผู้ใช้ทุกคนผ่าน `MenuService::forget()` ไม่ได้เพราะ migration ไม่ควรผูกกับ
     * บริการที่อาจเปลี่ยน · ที่นี่จึงลบผ่าน store โดยตรงเมื่อทำได้ และปล่อยให้
     * แคชหมดอายุเองภายในหนึ่งชั่วโมงเมื่อทำไม่ได้
     */
    private function forgetMenuCaches(): void
    {
        $store = config('cache.default');

        if ($store === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();

            return;
        }

        if ($store === 'file') {
            // ไฟล์แคชตั้งชื่อจาก hash ของคีย์ จึงเลือกลบทีละคีย์ไม่ได้ — ปล่อยหมดอายุเอง
        }
    }
};
