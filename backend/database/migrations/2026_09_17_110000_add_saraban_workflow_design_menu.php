<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * เพิ่มเมนู "สายการเดินหนังสือ" ให้ระบบที่ติดตั้งไปแล้ว (RISK-26 · NAV §5.2)
 *
 * หน้าจอจัดการสายงานมีแล้ว แต่ seeder ไม่ถึงเครื่องที่รัน migration ไปแล้ว
 * — ถ้าไม่มี migration นี้ ผู้ดูแลจะแก้สายงานไม่ได้ทั้งที่หน้าจอมีอยู่จริง
 *
 * sync ทั้งกิ่งการจัดการเพราะสิทธิ์ของกลุ่มเปลี่ยนไปด้วย (เพิ่ม
 * `saraban.workflow.manage`) — ถ้า sync แต่ลูก ผู้ที่ถือสิทธิ์นี้อย่างเดียว
 * จะไม่เห็นกลุ่มเลยทั้งที่มีสิทธิ์เข้าหน้าข้างใน
 */
return new class extends Migration
{
    public function up(): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', 'saraban_admin');
        $parent = Menu::query()->where('code', 'saraban_admin')->first();

        if ($branch === null || $parent === null) {
            return;
        }

        $parent->permissions()->sync($this->permissionIds($branch['permissions'] ?? []));

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

            $menu->permissions()->sync($this->permissionIds($child['permissions'] ?? []));
        }

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    /** @param array<int,string> $names */
    private function permissionIds(array $names): array
    {
        return collect($names)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
            ->all();
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — การลบเมนูคืนจะพาหน้าที่ใช้งานอยู่หายไปจากแถบเมนู
    }
};
