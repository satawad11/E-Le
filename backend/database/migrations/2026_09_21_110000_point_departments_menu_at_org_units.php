<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เมนู "จัดการแผนกวิชา" ชี้ไปหน้าจัดการผังองค์กร
 *
 * ผังองค์กรเหลือชุดเดียว — โครง `departments` ถูกถอดออก หน้าเดิมกลายเป็นหน้าจัดการ
 * `org_units` ซึ่งเพิ่มความสามารถที่โครงเก่าทำไม่ได้: เลือกหน่วยงานแม่ได้ ผังจึงลึก
 * เกินสองชั้นได้จริง (ฝ่าย > กลุ่มงาน > งาน)
 *
 * เปลี่ยนชื่อและเส้นทางของเมนูเดิมเท่านั้น ไม่เพิ่มและไม่ลบเมนูใด ผู้ใช้ที่เคยเห็น
 * เมนูนี้จึงยังเห็นเหมือนเดิม และสิทธิ์ที่ใช้ยังเป็น `manage_departments` ตัวเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = collect(MenuSeeder::TREE)->firstWhere('code', 'settings');
        $definition = collect($settings['children'] ?? [])->firstWhere('code', 'settings.departments');

        if ($definition === null) {
            return;
        }

        Menu::query()->where('code', 'settings.departments')->update([
            'title_th' => $definition['title_th'],
            'title_en' => $definition['title_en'],
            'route_name' => $definition['route_name'],
            'route_path' => $definition['route_path'],
        ]);

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        // หน้าเดิมไม่มีอยู่แล้ว การชี้เมนูกลับไปจะพาผู้ใช้ไปยังเส้นทางที่ redirect ทันที
    }
};
