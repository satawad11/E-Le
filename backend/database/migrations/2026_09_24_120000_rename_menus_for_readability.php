<?php

use App\Models\Menu;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * พาชื่อเมนูชุดใหม่ไปยังระบบที่ติดตั้งไปแล้ว (ADR-MENU-NAMING-20260910)
 *
 * `MenuSeeder::TREE` เป็นจุดแก้ชื่อเมนูเพียงจุดเดียว แต่ seeder ไม่ถูกรันซ้ำบน
 * ระบบที่ติดตั้งแล้ว ชื่อใหม่จึงไปไม่ถึงผู้ใช้ถ้าไม่มี migration พาไป
 *
 * เปลี่ยนเฉพาะ `title_th` / `title_en` ของ 14 รายการที่ระบุไว้ด้านล่างเท่านั้น
 * ไม่แตะ `code`, `route_name`, `route_path`, สิทธิ์ หรือลำดับ — ตัวตนของเมนูคือ
 * `code` การเปลี่ยนชื่อจึงไม่กระทบสิทธิ์หรือเส้นทางของใคร
 *
 * ค่าที่เขียนลงไปอ่านจาก `MenuSeeder::TREE` ไม่ได้พิมพ์ซ้ำไว้ที่นี่ เพื่อไม่ให้
 * ชื่อเมนูมีสองแหล่งที่เพี้ยนจากกันได้
 */
return new class extends Migration
{
    /**
     * เมนูที่เปลี่ยนชื่อในรอบนี้ — ระบุเป็นรายการเพื่อไม่ให้ migration นี้
     * ไปทับชื่อของเมนูอื่นที่ไม่เกี่ยวข้อง
     */
    private const RENAMED = [
        'leave.management',
        'leave.promotion_screening',
        'leave.governance',
        'attendance.history',
        'attendance.records',
        'attendance.summary',
        'overtime.summary',
        'saraban.numbers',
        'saraban.documents',
        'saraban.reports',
        'saraban.operations',
        'saraban.compliance',
        'users.menus',
        'settings.departments',
    ];

    public function up(): void
    {
        $definitions = $this->flatten(MenuSeeder::TREE);

        foreach (self::RENAMED as $code) {
            $definition = $definitions[$code] ?? null;

            if ($definition === null) {
                continue;
            }

            Menu::query()->where('code', $code)->update([
                'title_th' => $definition['title_th'],
                'title_en' => $definition['title_en'],
            ]);
        }

        /*
         * แถบเมนูของผู้ใช้ถูก cache ไว้รายคน — ถ้าไม่ล้าง ผู้ใช้จะยังเห็นชื่อเดิม
         * จนกว่า cache จะหมดอายุเอง
         */
        if (config('cache.default') === 'database' && DB::getSchemaBuilder()->hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%user_menu_tree_%')->delete();
        }
    }

    public function down(): void
    {
        /*
         * ไม่คืนชื่อเดิม — ชื่อเก่าคือชื่อที่ ADR-MENU-NAMING-20260910 ตัดสินว่าใช้ไม่ได้
         * (ซ้ำกันข้ามกลุ่ม · ไม่บอกขอบเขต · ยาวเกินแถบเมนู) การย้อนกลับไปจึงเป็นการ
         * พาปัญหากลับมา ไม่ใช่การกู้ข้อมูล · ชื่อเมนูไม่ใช่ข้อมูลของผู้ใช้ที่จะสูญหาย
         */
    }

    /**
     * แผ่โครงเมนูทั้งต้นให้ค้นด้วย `code` ได้ตรง ๆ
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,array<string,mixed>>
     */
    private function flatten(array $nodes): array
    {
        $flat = [];

        foreach ($nodes as $node) {
            if (isset($node['code'])) {
                $flat[$node['code']] = $node;
            }

            if (! empty($node['children'])) {
                $flat += $this->flatten($node['children']);
            }
        }

        return $flat;
    }
};
