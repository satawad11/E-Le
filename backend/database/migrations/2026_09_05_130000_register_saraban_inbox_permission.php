<?php

use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * INT-13 — เพิ่มชื่อสิทธิ์ `saraban.inbox.view` บนระบบที่ติดตั้งไปแล้ว
 *
 * เหตุผลเดียวกับ `2026_09_02_100000_register_saraban_permission_namespace.php`:
 * migration รันได้ครั้งเดียว ระบบที่ติดตั้งไปก่อนหน้านี้จึงไม่ได้ชื่อสิทธิ์ที่เพิ่ม
 * เข้าสารบัญหลังจากนั้น · เรียก seeder ตัวเดิมแทนการคัดลอกรายชื่อมาไว้ที่นี่
 * เพื่อไม่ให้เกิดแหล่งความจริงที่สอง (seeder เป็น idempotent)
 *
 * **สร้างชื่ออย่างเดียว ไม่แจกให้ใคร** — ผู้ดูแลต้องมอบหมาย `saraban.inbox.view`
 * ให้บทบาทของข้าราชการทั่วไปตอนเปิดใช้งานโมดูล ไม่งั้นหนังสือที่เวียนถึงเขา
 * จะไม่มีทางเปิดเจอ (ระบุไว้ในคู่มือติดตั้งของ INT-13)
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
