<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * แยก "รายงาน" ออกจากศูนย์จัดการเอกสาร ให้ไปอยู่ในหมวดรายงาน
 *
 * เดิมรายการรายงานที่ผู้ดูแลออกแบบเองเป็นแท็บหนึ่งของหน้าตั้งค่าเอกสาร ผู้ที่ต้องการ
 * เพียงเปิดดูรายงานจึงต้องเข้าเมนู "ตั้งค่าระบบ" ซึ่งเป็นคนละเรื่องกับสิ่งที่กำลังทำ
 *
 * migration นี้พาโครงเมนูใหม่ไปให้ระบบที่ติดตั้งไปแล้ว (migration ที่รันไปแล้วไม่รันซ้ำ
 * ดู `MenuDriftTest`) โดย **เพิ่มและปรับชื่อเท่านั้น ไม่ลบเมนูใด** และล้างเฉพาะแคชเมนูรายผู้ใช้
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addCustomReportsMenu();
        $this->renameDocumentCenterMenu();

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    /** เพิ่มเมนู "รายงานที่ออกแบบเอง" ใต้หมวดรายงาน */
    private function addCustomReportsMenu(): void
    {
        $branch = collect(MenuSeeder::TREE)->firstWhere('code', 'reporting');
        $parent = Menu::query()->where('code', 'reporting')->first();

        if ($branch === null || $parent === null) {
            return;
        }

        foreach ($branch['children'] ?? [] as $order => $child) {
            $menu = Menu::updateOrCreate(['code' => $child['code']], [
                'title_th' => $child['title_th'],
                'title_en' => $child['title_en'],
                'module' => $child['module'] ?? $branch['module'] ?? null,
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

    /**
     * เปลี่ยนชื่อเมนูศูนย์จัดการเอกสารให้ตรงกับสิ่งที่หน้านั้นทำจริง
     *
     * ไม่แตะเส้นทางหรือสิทธิ์ — เปลี่ยนเฉพาะชื่อที่แสดง
     */
    private function renameDocumentCenterMenu(): void
    {
        $item = collect(MenuSeeder::TREE)
            ->firstWhere('code', 'settings')['children'] ?? [];

        $definition = collect($item)->firstWhere('code', 'settings.document_center');

        if ($definition === null) {
            return;
        }

        Menu::query()->where('code', 'settings.document_center')->update([
            'title_th' => $definition['title_th'],
            'title_en' => $definition['title_en'],
        ]);
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
