<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * ปรับปรุงและยุบรวมเมนูงานสารบรรณในระบบที่ติดตั้งแล้ว ให้ตรงกับ MenuSeeder::TREE
 *
 * ยุบเมนูย่อยจากเดิม 21 เมนู เหลือ 7 เมนูหลักในกลุ่มสารบรรณ และ 1 เมนูศูนย์จัดการในกลุ่มการจัดการ
 */
return new class extends Migration
{
    private const OBSOLETE_MENU_CODES = [
        'saraban.queue',
        'saraban.incoming.pending',
        'saraban.incoming.register',
        'saraban.numbers',
        'saraban.incoming',
        'saraban.documents.outgoing',
        'saraban.documents.internal',
        'saraban.documents',
        'saraban.cases',
        'saraban.authority',
        'saraban.workflow.design',
        'saraban.workflow.simulate',
        'saraban.policies',
        'saraban.number_series',
        'saraban.operations',
        'saraban.compliance',
    ];

    public function up(): void
    {
        // 1. นำเมนูเก่าที่ถูกยุบรวมเข้าหน้า Hub ออกจากระบบ
        Menu::query()->whereIn('code', self::OBSOLETE_MENU_CODES)->delete();

        // 2. Sync เมนูทั้งหมดตาม Tree ล่าสุด
        foreach (MenuSeeder::TREE as $branchIndex => $branch) {
            $parent = $this->upsert($branch, $branchIndex + 1, null);

            foreach ($branch['children'] ?? [] as $childIndex => $child) {
                $child['module'] ??= $branch['module'];
                $this->upsert($child, $childIndex + 1, $parent->id);
            }
        }

        $this->forgetMenuCaches();
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — เมนูใหม่เป็นมาตรฐานปัจจุบัน
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

        $permissionIds = collect($item['permissions'] ?? [])
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
