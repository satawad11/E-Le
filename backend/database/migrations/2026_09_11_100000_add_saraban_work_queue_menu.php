<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * เพิ่มเมนู "รอฉันดำเนินการ" ให้ระบบที่ติดตั้งไปแล้ว (NAV §4.7)
 *
 * **ที่มา:** การตรวจ navigation เมื่อ 2026-09-05 พบว่าไม่มีทางถามว่า "หนังสือ
 * ฉบับใดรอฉันพิจารณาหรือลงนามอยู่" · ผู้พิจารณาและผู้ลงนามต้องเปิดทะเบียนแล้ว
 * ไล่เปิดทีละฉบับ ซึ่งเป็นงานประจำวันของสองบทบาทนั้น
 *
 * ใช้รูปแบบเดียวกับ `2026_09_09_100000_resync_saraban_menus_with_tree` —
 * **เพิ่มและปรับเฉพาะกิ่งสารบรรณ ไม่ลบอะไรเลย** และล้างเฉพาะแคชเมนู ·
 * ไม่เรียก `MenuSeeder::run()` ทั้งตัวเพราะจะลบเมนูที่ผู้ดูแลสร้างเองและ
 * `Cache::flush()` ทั้งระบบ
 */
return new class extends Migration
{
    public function up(): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', 'saraban');

        if ($branch === null) {
            return;
        }

        $parent = Menu::query()->where('code', 'saraban')->first();

        if ($parent === null) {
            return;
        }

        foreach ($branch['children'] ?? [] as $order => $child) {
            $child['module'] ??= $branch['module'];

            $menu = Menu::updateOrCreate(['code' => $child['code']], [
                'title_th' => $child['title_th'],
                'title_en' => $child['title_en'],
                'module' => $child['module'],
                'icon' => $child['icon'] ?? null,
                'route_name' => $child['route_name'] ?? null,
                'route_path' => $child['route_path'] ?? null,
                'component_path' => null,
                'parent_id' => $parent->id,
                // ลำดับเปลี่ยนทั้งกิ่งเพราะมีรายการแทรกกลาง
                'sort_order' => $order + 1,
                'is_external' => false,
            ]);

            $permissionIds = collect($child['permissions'] ?? [])
                ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
                ->all();

            $menu->permissions()->sync($permissionIds);
        }

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — การลบเมนูคืนจะพาหน้าที่ใช้งานอยู่หายไปจากแถบเมนู
    }
};
