<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * เพิ่มเมนู "จองเลขหนังสือ" ให้ระบบที่ติดตั้งไปแล้ว (SoT §9 · NAV §4.3)
 *
 * **ที่มา:** `NumberingService` มีความสามารถจอง/คืน/ยกเลิกเลขครบตั้งแต่ INT-06
 * แต่ **ไม่เคยถูกเปิดผ่าน HTTP** — เลขออกอัตโนมัติภายในสายงานเดินหนังสือเท่านั้น ·
 * สิทธิ์สี่ข้อจึงอยู่ในสารบัญโดยไม่มี route ใดใช้ และเจ้าหน้าที่ธุรการจองเลข
 * ล่วงหน้าไม่ได้เลย ทั้งที่เป็นงานปกติของงานสารบรรณ
 *
 * ใช้รูปแบบเดียวกับ migration เมนูก่อนหน้า — **ปรับเฉพาะกิ่งสารบรรณ ไม่ลบอะไรเลย**
 * และล้างเฉพาะแคชเมนู
 */
return new class extends Migration
{
    public function up(): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', 'saraban');
        $parent = Menu::query()->where('code', 'saraban')->first();

        if ($branch === null || $parent === null) {
            return;
        }

        // กลุ่มแม่ต้องประกาศสิทธิ์ของลูกใหม่ด้วย ไม่งั้นผู้ที่มีเฉพาะสิทธิ์จองเลขจะไม่เห็นกลุ่ม
        $this->syncPermissions($parent, $branch['permissions'] ?? []);

        foreach ($branch['children'] ?? [] as $order => $child) {
            $menu = Menu::updateOrCreate(['code' => $child['code']], [
                'title_th' => $child['title_th'],
                'title_en' => $child['title_en'],
                'module' => $child['module'] ?? $branch['module'],
                'icon' => $child['icon'] ?? null,
                'route_name' => $child['route_name'] ?? null,
                'route_path' => $child['route_path'] ?? null,
                'component_path' => null,
                'parent_id' => $parent->id,
                // ลำดับเปลี่ยนทั้งกิ่งเพราะมีรายการแทรกกลาง
                'sort_order' => $order + 1,
                'is_external' => false,
            ]);

            $this->syncPermissions($menu, $child['permissions'] ?? []);
        }

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — การลบเมนูคืนจะพาหน้าที่ใช้งานอยู่หายไปจากแถบเมนู
    }

    /** @param array<int,string> $names */
    private function syncPermissions(Menu $menu, array $names): void
    {
        // สร้างชื่อสิทธิ์ที่ยังไม่มีเท่านั้น — ไม่ได้แจกสิทธิ์ให้ผู้ใช้คนใด
        $ids = collect($names)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
            ->all();

        $menu->permissions()->sync($ids);
    }
};
