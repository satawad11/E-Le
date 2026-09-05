<?php

use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * INT-05 — เพิ่มเมนูงานสารบรรณให้ระบบที่ติดตั้งไปแล้ว
 *
 * `MenuSeeder::TREE` เป็นแหล่งความจริงเดียวของแถบเมนู แต่ seeder ถูกเรียกเฉพาะ
 * ตอนติดตั้งใหม่ ระบบที่ใช้งานอยู่แล้วจึงจะไม่เห็นเมนูใหม่เลยถ้าไม่มี migration นี้
 * (เหตุผลเดียวกับ `2026_08_28_090000_resync_menus_with_application_routes.php`)
 *
 * seeder เป็น idempotent (`updateOrCreate` + `sync`) จึงรันซ้ำได้ และจะจัดเมนู
 * ทั้งระบบให้ตรงกับ `TREE` เสมอ — เมนูที่ไม่อยู่ใน `TREE` จะถูกลบเพราะชี้ไปยัง
 * หน้าที่ไม่มีอยู่จริง ซึ่งเป็นพฤติกรรมที่ตั้งใจของ seeder ตัวนี้อยู่แล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MenuSeeder)->run();
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — เมนูถูกจัดให้ตรงกับ TREE เสมอเมื่อ seeder ถูกเรียกครั้งถัดไป
    }
};
