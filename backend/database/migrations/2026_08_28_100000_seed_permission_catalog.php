<?php

use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * CLEANUP-01 (D5) — สร้างชื่อสิทธิ์ที่โค้ดบังคับใช้แต่ไม่เคยถูกสร้าง
 *
 * `leave.management.manage` และ `system.role.manage` มีนิยามอยู่ใน
 * `StandardRolesAndPermissionsSeeder` เท่านั้น ซึ่งถูกกันไม่ให้รันตอนติดตั้ง
 * (สร้างบทบาทชื่อไทยอีกชุดที่ระบบไม่ได้ใช้) ระบบที่ติดตั้งไปแล้วจึงไม่มีชื่อเหล่านี้
 * endpoint และเมนูที่อ้างถึงจึงใช้ได้เฉพาะ Super Admin ที่ผ่าน `Gate::before`
 *
 * migration นี้สร้าง **ชื่อ** อย่างเดียว ไม่แจกสิทธิ์ให้บทบาทหรือผู้ใช้คนใด
 * ขอบเขตของทุกคนจึงเท่าเดิมทุกประการ ผู้ดูแลมอบหมายต่อได้ที่หน้าจัดการสิทธิ์
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PermissionCatalogSeeder)->run();
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — การลบชื่อสิทธิ์จะตัดสิทธิ์ที่ผู้ดูแลมอบหมายไว้หลังจากนี้ไปด้วย
    }
};
