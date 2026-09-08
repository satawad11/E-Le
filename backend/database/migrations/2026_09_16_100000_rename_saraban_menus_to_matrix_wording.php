<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เปลี่ยนชื่อเมนูย่อยของงานสารบรรณให้ตรงศัพท์ใน NAVIGATION-ACTION-PERMISSION-MATRIX §2
 *
 * ชื่อกลุ่มบนสุดคง "งานสารบรรณ" ไว้ตามที่เจ้าของระบบเลือก — breadcrumb ของ 24
 * เส้นทางอ้างชื่อนี้อยู่ จึงไม่ต้องแก้ตามด้วย
 *
 * **แก้เฉพาะชื่อ** ไม่แตะ `route_path` สิทธิ์ หรือลำดับ — ตัวตนของเมนูคือ `code`
 * การเปลี่ยนชื่อจึงไม่กระทบสิทธิ์หรือเส้นทางใด
 *
 * **ข้อควรทราบ:** ถ้าผู้ดูแลระบบเคยเปลี่ยนชื่อเมนูเหล่านี้เองผ่านหน้าจัดการเมนู
 * ชื่อนั้นจะถูกเขียนทับด้วยค่าจาก `MenuSeeder::TREE` — เป็นพฤติกรรมเดียวกับ
 * migration เมนูทุกตัวก่อนหน้า เพราะ `TREE` เป็นแหล่งความจริงเดียวของแถบเมนู
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['saraban', 'saraban_admin'] as $code) {
            $branch = collect(MenuSeeder::TREE)->firstWhere('code', $code);

            if ($branch === null) {
                continue;
            }

            foreach ($branch['children'] ?? [] as $child) {
                Menu::query()
                    ->where('code', $child['code'])
                    ->update([
                        'title_th' => $child['title_th'],
                        'title_en' => $child['title_en'],
                    ]);
            }
        }

        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — ชื่อเดิมไม่มีค่าในเชิงข้อมูล และการย้อนจะทำให้แถบเมนูขัดกับหน้าจอ
    }
};
