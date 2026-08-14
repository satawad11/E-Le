<?php

use Database\Seeders\OperationalPermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * LEGACY-04 (ปิดงาน) — เติม permission ที่ระบบบังคับใช้จริงแต่ไม่เคยถูกสร้าง
 *
 * `LEGACY-04-REPORT.md` พบว่า route บังคับ permission 7 ตัวที่ไม่มีในฐานข้อมูล
 * ผลคือ endpoint เหล่านั้นใช้ได้เฉพาะ Super Admin และที่ร้ายที่สุดคือ
 * **บุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้เลย**
 *
 * เจ้าของระบบอนุมัติให้เติมเมื่อ 2026-08-14 (Decision 1 / Decision 2)
 *
 * ตัวชุดสิทธิ์และการแจกอยู่ใน `OperationalPermissionSeeder` เพื่อให้ระบบที่
 * ติดตั้งแล้ว (migration) กับระบบที่ติดตั้งใหม่/ชุดทดสอบ (seeder) ใช้นิยามเดียวกัน
 *
 * บนระบบที่ติดตั้งแล้ว role มีอยู่ครบตอน migrate จึงได้รับสิทธิ์ทันที
 * บนฐานข้อมูลว่าง (ชุดทดสอบ) จะสร้างเฉพาะตัว permission แล้วให้ seeder
 * แจกสิทธิ์อีกครั้งหลัง role ถูกสร้าง
 *
 * additive ทั้งหมด — ไม่ถอนสิทธิ์ของ role ใด
 */
return new class extends Migration
{
    public function up(): void
    {
        (new OperationalPermissionSeeder)->run();
    }

    public function down(): void
    {
        /*
         * จงใจไม่ถอนสิทธิ์ที่แจกไปแล้ว — การถอนจะทำให้บุคลากรลงเวลาไม่ได้อีกครั้ง
         * ซึ่งเป็นข้อบกพร่องที่ migration นี้ตั้งใจแก้
         * ถ้าต้องการย้อนจริงต้องทำด้วยมือพร้อมประเมินผลกระทบ
         */
    }
};
