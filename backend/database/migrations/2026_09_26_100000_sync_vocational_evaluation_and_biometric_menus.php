<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * ซิงค์เมนูการประเมินการปฏิบัติงานและการบริหารจัดการข้อมูลชีวมิติ (Sync Vocational Evaluation & Biometric Hub Menus)
 *
 * นำส่งเมนู `leave.vocational_evaluation` (การประเมิน 23 วันเพื่อเลื่อนขั้นเงินเดือน)
 * และ `attendance.biometric_hub` (จัดการข้อมูลชีวมิติ) ให้กับระบบที่ติดตั้งแล้ว
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

        Menu::query()->whereNotIn('code', $keptCodes)->delete();

        $this->forgetMenuCaches();
    }

    public function down(): void
    {
        // โครงสร้างเมนูซิงค์ตาม MenuSeeder::TREE ไม่จำเป็นต้องย้อนกลับ
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
