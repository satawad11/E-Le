<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-GAP-03 (INT-04) — ทำให้การแก้ audit ย้อนหลัง "ตรวจจับได้" (SoT §25)
 *
 * เดิม `audit_logs` เป็น append-only ด้วย **ข้อตกลงในโค้ด** เท่านั้น
 * (`AuditLog::booted()` โยน exception ตอน update/delete) ซึ่งกันได้เฉพาะโค้ดของแอป
 * ใครก็ตามที่เข้าถึงฐานข้อมูลได้ยังแก้หรือลบแถวได้ตามใจ โดยไม่มีอะไรบอกได้เลยว่า
 * ประวัติถูกแก้ — ซึ่งไม่พอสำหรับหลักฐานทางราชการ
 *
 * เพิ่มโซ่แฮช: แต่ละแถวเก็บแฮชของเนื้อหาตัวเอง **ผูกกับแฮชของแถวก่อนหน้า**
 * การแก้แถวใดแถวหนึ่งจะทำให้โซ่ตั้งแต่จุดนั้นเป็นต้นไปไม่ตรงทันที และการลบแถว
 * ตรงกลางก็ทำให้โซ่ขาด ตรวจได้ด้วย `php artisan audit:verify`
 *
 * **เป็นการเพิ่มอย่างเดียว** — สองคอลัมน์เป็น nullable แถวเก่าที่มีอยู่ยังคงอยู่
 * เหมือนเดิมและอ่านได้ตามปกติ ไม่มีการเขียนทับข้อมูลใด ๆ · แถวเก่าจะไม่มีแฮช
 * ซึ่งถูกต้องตามความจริง: ระบบรับรองความต่อเนื่องได้เฉพาะช่วงที่เริ่มลงแฮชแล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // แฮชของแถวนี้ (sha256 ในรูป hex = 64 อักขระ)
            $table->string('entry_hash', 64)->nullable()->after('correlation_id');
            // แฮชของแถวก่อนหน้า — null เฉพาะแถวแรกของโซ่
            $table->string('previous_hash', 64)->nullable()->after('entry_hash');

            $table->index('entry_hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['entry_hash']);
            $table->dropColumn(['entry_hash', 'previous_hash']);
        });
    }
};
