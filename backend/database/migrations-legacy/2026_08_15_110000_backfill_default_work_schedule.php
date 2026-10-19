<?php

use Database\Seeders\WorkScheduleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-06 · 06-2 — สร้างตารางเวลาเริ่มต้นให้ระบบที่ติดตั้งไปแล้ว
 *
 * ตัวชุดข้อมูลอยู่ใน `WorkScheduleSeeder` ที่เดียว เพื่อให้ระบบที่ติดตั้งแล้ว
 * (migration) กับติดตั้งใหม่/ชุดทดสอบ (seeder) ได้ผลตรงกัน
 *
 * **ไม่แตะ `users.work_start_time` / `work_end_time`** — คงไว้เป็นข้อมูลอ่านอย่างเดียว
 * ตามหลักการเดียวกับที่ LEGACY-05 คงตาราง approval เดิมไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_schedules')) {
            return;
        }

        (new WorkScheduleSeeder)->run();
    }

    public function down(): void
    {
        /*
         * ไม่ถอนข้อมูล — ถ้าถอนตารางเวลาออก การคำนวณมาสาย/วันทำงานจะกลับไป
         * ไม่มีฐานอ้างอิง ถ้าต้องการย้อนจริงให้ลบตารางทั้งชุดผ่าน migration ที่สร้างมัน
         */
    }
};
