<?php

use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * CLEANUP-01 (D1) — ทำให้ตาราง `menus` ตรงกับหน้าจอที่มีอยู่จริง
 *
 * ระบบที่ติดตั้งไปแล้วมีเมนูค้างอยู่จากหลายแหล่ง (MenuSeeder ชุดเดิม,
 * `2026_08_09_020000_seed_leave_governance_access`,
 * `2026_08_09_030000_add_governance_menus`) ซึ่งชี้ไปยัง route ที่ไม่มีอยู่ใน
 * `router/index.js` เช่น `/dashboard`, `/calendar`, `/attendance/me`,
 * `/admin/personnel-types`, `/admin/leave-calendar`
 *
 * ตอนที่เมนูยังไม่มีผลกับสิ่งที่ผู้ใช้เห็น (แถบเมนูเขียนตายตัวใน App.vue) ความเพี้ยนนี้
 * ไม่มีใครสังเกต แต่เมื่อแถบเมนูอ่านจากตารางนี้จริง ๆ ทุกแถวที่ผิดจะกลายเป็นเมนูที่กดแล้ว 404
 *
 * `MenuSeeder` เป็น idempotent และเป็นแหล่งความจริงเดียว — migration นี้จึงเรียกมันตรง ๆ
 * แบบเดียวกับ `2026_08_14_100000_seed_operational_permissions`
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MenuSeeder)->run();
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — เมนูชุดเดิมชี้ไปยังหน้าที่ไม่มีอยู่จริง การคืนค่าจึงมีแต่จะพาไป 404
    }
};
