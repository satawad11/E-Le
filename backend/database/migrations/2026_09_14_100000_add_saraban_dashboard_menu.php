<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * เพิ่มเมนู "หน้าแรกงานสารบรรณ" ให้ระบบที่ติดตั้งไปแล้ว (NAV §4.1)
 *
 * หน้าแรกรวมตัวเลขสรุปและงานของฉันไว้ที่เดียว — ตอบคำถาม "วันนี้ต้องทำอะไร"
 * ตั้งแต่หน้าจอแรกที่เปิด แทนที่จะต้องไล่เปิดทีละเมนู
 *
 * ใช้รูปแบบเดียวกับ migration เมนูก่อนหน้า — ปรับเฉพาะกิ่งสารบรรณ ไม่ลบอะไรเลย
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
                'sort_order' => $order + 1,
                'is_external' => false,
            ]);

            $ids = collect($child['permissions'] ?? [])
                ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
                ->all();

            $menu->permissions()->sync($ids);
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
