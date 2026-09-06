<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * จัดเมนูสารบรรณให้ตรงกับ SARABAN-NAVIGATION-ACTION-PERMISSION-MATRIX §2
 *
 * เพิ่มทางเข้าที่เอกสารกำหนดแต่ยังไม่มี:
 *   - กล่องรอลงทะเบียน
 *   - ทะเบียนหนังสือส่ง / ทะเบียนหนังสือภายใน
 *   - ติดตามการกระจายเอกสาร
 *   - Feature Flags และ Integration (ชี้ไปหน้าเดิมของระบบ)
 *
 * และเรียงลำดับใหม่ตามเส้นทางเดินของหนังสือจริง พร้อมปรับชื่อบางรายการให้ตรง
 * ศัพท์ในเอกสาร (ทะเบียนรับหนังสือ → ทะเบียนหนังสือรับ)
 *
 * **ไม่ลบเมนูใด** — `updateOrCreate` ตามรหัส และเมนูที่ผู้ดูแลเพิ่มเองไม่ถูกแตะ
 * ด้วยเหตุผลเดียวกับ migration เมนูก่อนหน้า (การรัน seeder เต็มชุดจะลบของที่
 * ผู้ดูแลสร้างไว้เอง)
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['saraban', 'saraban_admin'] as $code) {
            $this->syncBranch($code);
        }

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    private function syncBranch(string $code): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', $code);
        $parent = Menu::query()->where('code', $code)->first();

        if ($branch === null || $parent === null) {
            return;
        }

        // สิทธิ์ของกลุ่มเปลี่ยนไปด้วย — ถ้าไม่ sync ผู้ที่มีสิทธิ์ใหม่จะไม่เห็นกลุ่มเลย
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
