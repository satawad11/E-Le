<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-ENGINE-02/06 — ค่าชดเชยเครื่องพิมพ์ และนิยามรายงาน
 *
 * Print calibration แยกจาก field mapping โดยเจตนา — เครื่องพิมพ์แต่ละเครื่องเพี้ยนไม่เท่ากัน
 * ห้ามขยับ mapping หลักเพื่อแก้ปัญหาของเครื่องใดเครื่องหนึ่ง
 * ลำดับการใช้: global → template → ผู้ใช้ (ค่าที่เจาะจงกว่าอยู่ทับ)
 *
 * Report definition เก็บ "โครงรายงาน" ไว้ในฐานข้อมูล เพื่อให้เพิ่มรายงานใหม่ได้จากหน้าผู้ดูแล
 * โดยดึงข้อมูลผ่าน Data Provider ที่ whitelist ไว้เท่านั้น (ห้าม SQL อิสระ)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_print_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20)->default('GLOBAL'); // GLOBAL|TEMPLATE|USER
            $table->foreignId('document_template_id')->nullable()->constrained('document_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('printer_profile', 120)->nullable();
            $table->decimal('x_offset', 6, 2)->default(0);
            $table->decimal('y_offset', 6, 2)->default(0);
            $table->decimal('scale', 5, 3)->default(1.000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['scope', 'document_template_id', 'user_id'], 'document_calibration_lookup');
        });

        Schema::create('document_report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name_th');
            $table->string('module', 30)->default('leave');
            $table->string('data_source_code', 120);     // อ้าง document_data_sources.code
            $table->json('filters')->nullable();         // ชนิดตัวกรองที่เปิดใช้
            $table->json('columns')->nullable();         // key, label, width, align, format
            $table->json('grouping')->nullable();        // คอลัมน์ที่ใช้จัดกลุ่ม + subtotal
            $table->json('aggregates')->nullable();      // COUNT|SUM|AVG|MIN|MAX ต่อคอลัมน์
            $table->json('layout')->nullable();          // header/footer/signature/page number
            $table->string('orientation', 20)->default('portrait');
            $table->string('paper_size', 20)->default('A4');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['module', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_report_definitions');
        Schema::dropIfExists('document_print_calibrations');
    }
};
