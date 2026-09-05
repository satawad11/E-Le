<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-10 — วัตถุที่วางบนหน้าหนังสือใน PDF Workspace (SoT §14)
 *
 * **พิกัดเก็บเป็น PDF point วัดจากมุมซ้ายบนของหน้า** ซึ่งเป็นระบบเดียวกับที่
 * `CoordinateConverter` และ `PdfOverlayRenderer` ของ Document Engine เดิมใช้อยู่แล้ว
 * ไม่เก็บเป็นพิกัดหน้าจอหรือเปอร์เซ็นต์ เพราะ:
 *
 *   - พิกัดหน้าจอขึ้นกับระดับการซูมและขนาดจอของคนวาง — วางที่เครื่องหนึ่ง
 *     แล้วเรนเดอร์ออกมาเพี้ยนที่อีกเครื่องหนึ่ง
 *   - PDF point เป็นหน่วยเดียวกับที่ FPDI ใช้วาดจริง จึงไม่ต้องแปลงตอนเรนเดอร์
 *
 * **ผูกกับ `document_version_id` ไม่ใช่ `document_id`** ตาม SoT §8 — ตราประทับ
 * ที่วางบนต้นฉบับ ต้องไม่ย้ายตามไปอยู่บนฉบับแก้ไขที่มีเนื้อหาคนละหน้า
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_document_annotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_version_id')->constrained('saraban_document_versions')->cascadeOnDelete();

            $table->unsignedInteger('page');

            // stamp | text | date | qr
            $table->string('type', 20);

            /*
             * หน่วยเป็น PDF point จากมุมซ้ายบน · ใช้ decimal ไม่ใช่ float
             * เพราะตำแหน่งตราประทับบนหนังสือราชการต้องเสถียร ไม่คลาดเคลื่อนตาม
             * การปัดเศษของเลขทศนิยมลอยตัว
             */
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();

            // เนื้อหาเฉพาะชนิด เช่น ข้อความ ขนาดตัวอักษร หรือค่าที่ใช้สร้าง QR
            $table->json('payload')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['document_version_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_annotations');
    }
};
