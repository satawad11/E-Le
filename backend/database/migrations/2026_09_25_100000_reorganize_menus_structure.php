<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * ปรับปรุงและจัดระเบียบโครงสร้างเมนูระบบ (Menu Reorganization & Sort Order Alignment)
 *
 * อัปเดตลำดับการแสดงผล (sort_order), กลุ่มแม่ (parent_id), และชื่อเมนู ให้ตรงกับ `MenuSeeder::TREE`
 * สำหรับระบบที่ติดตั้งไปแล้ว เพื่อให้การจัดระเบียบเมนูส่งผลถึงผู้ใช้ทุกคนทันทีโดยไม่กระทบ URL หรือสิทธิ์ความปลอดภัย
 */
return new class extends Migration
{
    public function up(): void
    {
        $keptCodes = [];

        foreach (MenuSeeder::TREE as $order => $item) {
            $menu = $this->upsert($item, $order + 1, null);
            $keptCodes[] = $menu->code;

            foreach ($item['children'] ?? [] as $childOrder => $child) {
                $child['module'] ??= $item['module'];
                $keptCodes[] = $this->upsert($child, $childOrder + 1, $menu->id)->code;
            }
        }

        /*
         * เมนูที่หลุดออกจาก `MenuSeeder::TREE` ต้องหายไปจากระบบที่ติดตั้งแล้วด้วย
         * ไม่งั้นหัวข้อเดิม (เช่น `leave`, `attendance`) จะค้างเป็นกลุ่มเปล่าที่ทุกคน
         * มองเห็น เพราะลูกทั้งหมดถูกย้ายไปอยู่ใต้กลุ่มใหม่แล้ว
         */
        Menu::query()->whereNotIn('code', $keptCodes)->delete();

        $this->forgetMenuCaches();
    }

    public function down(): void
    {
        // โครงสร้างเมนูที่จัดระเบียบใหม่เป็น Single Source of Truth ไม่จำเป็นต้องย้อนกลับ
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

        $permissionNames = $item['permissions'] ?? [];
        $permissionIds = collect($permissionNames)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
            ->all();

        $menu->permissions()->sync($permissionIds);

        return $menu;
    }

    private function forgetMenuCaches(): void
    {
        $store = config('cache.default');

        if ($store === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }
};
