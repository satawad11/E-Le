<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-08 — ผลของการเดินเรื่องที่สะท้อนกลับมาที่หนังสือ
 *
 * `document_number_id` คือเลขที่หนังสือ ซึ่ง **ออกตอนลงนามเสร็จ ไม่ใช่ตอนสร้าง**
 * ตามระเบียบงานสารบรรณ — การออกเลขให้ฉบับร่างที่อาจไม่ได้ลงนาม จะทำให้ทะเบียน
 * มีเลขที่ไม่มีหนังสือจริงรองรับ
 *
 * แยกจาก `saraban_incoming_registrations.document_number_id` ซึ่งเป็น "เลขทะเบียนรับ"
 * คนละเลขกันตามที่ INT-07 อธิบายไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->foreignId('document_number_id')
                ->nullable()
                ->after('current_version_id')
                ->constrained('saraban_document_numbers')
                ->nullOnDelete();

            $table->timestamp('issued_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_number_id');
            $table->dropColumn('issued_at');
        });
    }
};
