<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * แยกเมนูสารบรรณเป็น "งานสารบรรณ" กับ "การจัดการงานสารบรรณ"
 * (NAVIGATION-ACTION-PERMISSION-MATRIX §2, §5)
 *
 * **ปัญหาที่แก้:** กิ่งเดียว 14 รายการปนงานประจำวันกับงานตั้งค่าไว้ด้วยกัน ·
 * เจ้าหน้าที่ที่เปิดเมนูเพื่อหางานของวันนี้ ต้องเลื่อนผ่านศูนย์นโยบาย ชุดเลขหนังสือ
 * และทะเบียนหลักฐาน ซึ่งเป็นงานที่ทำปีละไม่กี่ครั้ง
 *
 * **ย้ายเมนู ไม่สร้างใหม่และไม่ลบ** — ใช้ `code` เดิมทุกตัว จึงไม่กระทบสิทธิ์ที่
 * ผูกไว้และไม่ทำให้ลิงก์เดิมเสีย · เปลี่ยนเฉพาะ `parent_id` และ `sort_order`
 *
 * ไม่เรียก `MenuSeeder::run()` ทั้งตัว เพราะจะลบเมนูที่ผู้ดูแลสร้างเองและ
 * `Cache::flush()` ทั้งระบบ — รูปแบบเดียวกับ migration เมนูก่อนหน้า
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['saraban', 'saraban_admin'] as $groupCode) {
            $branch = collect(MenuSeeder::TREE)->firstWhere('code', $groupCode);

            if ($branch === null) {
                continue;
            }

            $order = collect(MenuSeeder::TREE)->search(fn (array $item) => $item['code'] === $groupCode);

            $group = $this->upsert($branch, is_int($order) ? $order + 1 : 30, null);

            foreach ($branch['children'] ?? [] as $index => $child) {
                $child['module'] ??= $branch['module'];
                $this->upsert($child, $index + 1, $group->id);
            }
        }

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        /*
         * ไม่ย้อนกลับ — การรวมกลับเป็นกิ่งเดียวไม่ได้แก้ปัญหาอะไร และเมนูทุกตัว
         * ยังชี้ไปหน้าเดิมทั้งหมด · ผู้ดูแลจัดกลุ่มใหม่เองได้จากหน้าจัดการเมนู
         */
    }

    /** @param array<string,mixed> $item */
    private function upsert(array $item, int $sortOrder, ?int $parentId): Menu
    {
        $menu = Menu::updateOrCreate(['code' => $item['code']], [
            'title_th' => $item['title_th'],
            'title_en' => $item['title_en'],
            'module' => $item['module'] ?? 'saraban',
            'icon' => $item['icon'] ?? null,
            'route_name' => $item['route_name'] ?? null,
            'route_path' => $item['route_path'] ?? null,
            'component_path' => null,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'is_external' => false,
        ]);

        // สร้างชื่อสิทธิ์ที่ยังไม่มีเท่านั้น — ไม่ได้แจกสิทธิ์ให้ผู้ใช้คนใด
        $permissionIds = collect($item['permissions'] ?? [])
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
            ->all();

        $menu->permissions()->sync($permissionIds);

        return $menu;
    }
};
