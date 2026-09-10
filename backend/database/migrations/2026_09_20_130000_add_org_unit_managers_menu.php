<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * เมนู "ผู้รับผิดชอบหน่วยงาน"
 *
 * `org_unit_managers` เป็นข้อมูลที่ตัวหาผู้อนุมัติใช้ตัดสินว่าหัวหน้าหน่วยงานของผู้ยื่น
 * คือใคร แต่ไม่เคยมีหน้าจอให้กรอก ตารางจึงว่างเปล่าทุกการติดตั้ง · หน้านี้คือที่กรอก
 * และเป็นที่ที่ช่อง "หัวหน้าแผนก" เดิม (ซึ่งไม่มีตรรกะใดอ่าน) ย้ายมาอยู่
 *
 * migration นี้พาเมนูไปให้ระบบที่ติดตั้งไปแล้ว เพราะ seeder ไม่รันซ้ำ (ดู `MenuDriftTest`)
 * เพิ่มอย่างเดียว ไม่ลบและไม่เปลี่ยนเมนูอื่น
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = collect(MenuSeeder::TREE)->firstWhere('code', 'settings');
        $parent = Menu::query()->where('code', 'settings')->first();

        if ($settings === null || $parent === null) {
            return;
        }

        $children = collect($settings['children'] ?? []);
        $definition = $children->firstWhere('code', 'settings.org_unit_managers');

        if ($definition === null) {
            return;
        }

        $menu = Menu::updateOrCreate(['code' => $definition['code']], [
            'title_th' => $definition['title_th'],
            'title_en' => $definition['title_en'],
            'module' => $definition['module'] ?? $settings['module'] ?? null,
            'icon' => $definition['icon'] ?? null,
            'route_name' => $definition['route_name'] ?? null,
            'route_path' => $definition['route_path'] ?? null,
            'component_path' => null,
            'parent_id' => $parent->id,
            // วางไว้ต่อจาก "จัดการแผนกวิชา" ตามลำดับใน seeder
            'sort_order' => $children->search(fn ($child) => $child['code'] === $definition['code']) + 1,
            'is_external' => false,
        ]);

        $menu->permissions()->sync(
            collect($definition['permissions'] ?? [])
                ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
                ->all()
        );

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — การลบเมนูคืนจะพาหน้าที่ใช้งานอยู่หายไปจากแถบเมนู
    }
};
