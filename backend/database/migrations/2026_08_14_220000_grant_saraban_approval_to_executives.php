<?php

use Database\Seeders\OperationalPermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * CORE CHECKPOINT (2026-08-14) — ให้ ผอ./รอง ผอ. กลั่นกรองและลงนามหนังสือราชการได้
 *
 * LEGACY-04 ปิดงานโดยจงใจไม่ให้สิทธิ์ชุดนี้ เพราะการตัดสินใจครั้งนั้นครอบคลุม
 * เฉพาะ `Document Officer` ผลข้างเคียงคือ `saraban.document.approve` และ `.sign`
 * เป็นของ Super Admin เท่านั้น สายงานหนังสือราชการจึงเดินไม่ครบและโมดูลสารบรรณ
 * ใช้งานจริงไม่ได้ — บันทึกไว้เป็นข้อค้างใน `LEGACY-04-FINAL-REPORT.md` ข้อ 9
 * และเป็นเหตุผลที่ `CORE-WF-002` ยังปิดไม่ได้
 *
 * เจ้าของระบบอนุมัติให้เติมเมื่อ 2026-08-14 เพื่อปิด CORE CHECKPOINT
 *
 * ตัวชุดสิทธิ์อยู่ใน `OperationalPermissionSeeder` ที่เดียวเหมือนเดิม
 * additive ล้วน — ไม่ถอนสิทธิ์ของใคร
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
         * ไม่ถอนสิทธิ์ — การถอนจะทำให้หนังสือราชการที่รออนุมัติค้างอยู่ไม่มีผู้อนุมัติ
         * ถ้าต้องการย้อนจริงต้องทำด้วยมือพร้อมประเมินผลกระทบ
         */
    }
};
