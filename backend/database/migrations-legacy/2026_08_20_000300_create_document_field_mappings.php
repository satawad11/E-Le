<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-ENGINE-02 — ตำแหน่งของฟิลด์บนแบบฟอร์ม PDF
 *
 * ผูกกับ "เวอร์ชัน" ไม่ใช่ตัวแม่แบบ เพราะเอกสารที่ออกไปแล้ว pin เวอร์ชันไว้
 * การขยับตำแหน่งฟิลด์จึงต้องเป็นเวอร์ชันใหม่เสมอ ไม่ย้อนกระทบเอกสารเก่า
 *
 * หน่วยพิกัดเก็บเป็น **PDF point** (72 pt/นิ้ว) วัดจากมุมซ้ายบนของหน้า
 * (มี CoordinateConverter แปลง mm ↔ pt และแปลงแกน Y ให้ FPDI)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_field_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_template_version_id')->constrained('document_template_versions')->cascadeOnDelete();
            $table->string('field_code', 120);           // อ้าง document_field_definitions.code
            $table->string('field_type', 20)->default('TEXT');
            $table->unsignedSmallInteger('page')->default(1);
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('width', 8, 2)->default(150);
            $table->decimal('height', 8, 2)->default(18);
            $table->string('font', 60)->nullable();      // null = ฟอนต์เริ่มต้นของระบบ (TH Sarabun)
            $table->decimal('font_size', 5, 2)->default(14);
            $table->string('align', 10)->default('left'); // left|center|right
            $table->string('overflow', 20)->default('shrink'); // shrink|wrap|clip
            $table->string('format')->nullable();        // รูปแบบวันที่/ตัวเลข
            $table->string('static_text')->nullable();   // ข้อความคงที่ (ไม่ผูกกับข้อมูล)
            $table->json('options')->nullable();         // เช่น checkbox mark, ค่าที่ถือว่าติ๊ก
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['document_template_version_id', 'page'], 'document_field_mapping_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_field_mappings');
    }
};
