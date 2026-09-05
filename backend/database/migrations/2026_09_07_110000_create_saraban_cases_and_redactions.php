<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-18 — สารบบเรื่อง การปกปิดข้อมูล และมุมมองที่บันทึกไว้ (SoT §22)
 *
 * **หลักที่บังคับทั้งไฟล์นี้: ตัวตนของหนังสือห้ามเปลี่ยน**
 *
 * การจัดกลุ่มเข้าสารบบ การปกปิดข้อมูล และการบันทึกมุมมอง เป็นสิ่งที่เกิด
 * *รอบ ๆ* หนังสือ ไม่ใช่ *กับ* หนังสือ — จึงไม่มีคอลัมน์ใดถูกเพิ่มลง
 * `saraban_documents` เลยแม้แต่คอลัมน์เดียว
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * สารบบเรื่อง (SoT §22)
         *
         * "เรื่องเดียวกัน" ในงานสารบรรณกินเวลาหลายปีและมีหนังสือหลายสิบฉบับ
         * ทั้งรับ ส่ง และภายใน · การจัดกลุ่มต้องเป็นชั้นบนที่ถอดออกได้
         * ไม่ใช่การแก้ตัวหนังสือให้ชี้ไปหากัน
         */
        Schema::create('saraban_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 60)->unique();
            $table->string('title');
            $table->text('summary')->nullable();

            // open | closed — ปิดแล้วยังอ่านได้ แต่เพิ่มหนังสือใหม่ไม่ได้
            $table->string('status', 20)->default('open');

            $table->foreignId('owner_org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('saraban_case_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('saraban_cases')->cascadeOnDelete();

            /*
             * **restrict ไม่ใช่ cascade** — การถอดหนังสือออกจากสารบบต้องเป็น
             * การกระทำที่ตั้งใจ ไม่ใช่ผลข้างเคียงของการลบอย่างอื่น
             */
            $table->foreignId('document_id')->constrained('saraban_documents')->restrictOnDelete();

            $table->string('note', 500)->nullable();
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['case_id', 'document_id'], 'saraban_case_document_unique');
        });

        /*
         * สำเนาที่ปกปิดข้อมูล (SoT §22)
         *
         * **แยกจากต้นฉบับเด็ดขาด** — ต้นฉบับไม่ถูกแตะ ไม่ถูกเขียนทับ และยังเปิดได้
         * โดยผู้มีสิทธิ์เสมอ · สำเนาที่ปกปิดเป็นไฟล์คนละไฟล์ที่มี hash ของตัวเอง
         *
         * ถ้าปกปิดทับต้นฉบับ ระบบจะทำลายหลักฐานที่ตัวเองมีหน้าที่เก็บ
         */
        Schema::create('saraban_redactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_id')->constrained('saraban_documents')->cascadeOnDelete();
            $table->foreignId('source_version_id')->constrained('saraban_document_versions')->restrictOnDelete();

            // เหตุผลบังคับ — การปกปิดโดยไม่ระบุเหตุผลตรวจสอบย้อนหลังไม่ได้
            $table->string('reason', 500);

            // พื้นที่ที่ถูกปิดทับ เป็นพิกัด PDF point เหมือน INT-10
            $table->json('areas');

            $table->string('pdf_file_path');
            $table->string('file_hash', 128);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('document_id');
        });

        /*
         * มุมมองที่บันทึกไว้ (SoT §22)
         *
         * คิวงานส่วนตัวและที่แบ่งปันกัน — เก็บ *เงื่อนไขการค้น* ไม่ใช่ *ผลลัพธ์*
         * ผลลัพธ์ที่แช่ไว้จะล้าสมัยทันทีและกลายเป็นข้อมูลที่หลอกผู้ใช้
         */
        Schema::create('saraban_saved_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);

            // documents | inbox — คิวงานคนละชนิดใช้ตัวกรองคนละชุด
            $table->string('resource', 30);

            $table->json('filters');
            $table->boolean('is_shared')->default(false);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['owner_id', 'resource']);
            $table->index(['is_shared', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_saved_views');
        Schema::dropIfExists('saraban_redactions');
        Schema::dropIfExists('saraban_case_documents');
        Schema::dropIfExists('saraban_cases');
    }
};
