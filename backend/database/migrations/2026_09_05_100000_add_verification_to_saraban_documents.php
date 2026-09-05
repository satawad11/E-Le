<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-12 — การตรวจสอบหนังสือจากภายนอก (SoT §12)
 *
 * ใช้รูปแบบเดียวกับที่โมดูลการลาใช้อยู่แล้วและพิสูจน์แล้วว่าทำงานได้จริง
 * (`leaves.verification_hash` + `content_hash`) — ไม่ประดิษฐ์กลไกใหม่
 *
 *   `verification_hash`  โทเคนสุ่ม 40 ตัวอักษรที่พิมพ์เป็น QR ลงบนหนังสือ
 *                        เดาไม่ได้ จึงเป็นทั้งตัวชี้เอกสารและตัวคุมการเข้าถึง
 *
 *   `content_hash`       ลายนิ้วมือของ **สาระสำคัญ** ที่ผนึกไว้ตอนออกหนังสือ
 *                        ถ้าข้อมูลถูกแก้ภายหลัง ค่าที่คำนวณใหม่จะไม่ตรงและ
 *                        หน้าตรวจสอบจะแจ้งว่าเนื้อหาถูกเปลี่ยน
 *
 * ต่างจาก `saraban_document_versions.file_hash` ซึ่งเป็นแฮชของ **ไฟล์** —
 * สองอย่างนี้ตอบคนละคำถาม: ไฟล์ถูกแก้ไหม vs ข้อมูลในทะเบียนถูกแก้ไหม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->string('verification_hash', 64)->nullable()->unique()->after('document_number_id');
            $table->string('content_hash', 128)->nullable()->after('verification_hash');
            $table->timestamp('finalized_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropUnique(['verification_hash']);
            $table->dropColumn(['verification_hash', 'content_hash', 'finalized_at']);
        });
    }
};
