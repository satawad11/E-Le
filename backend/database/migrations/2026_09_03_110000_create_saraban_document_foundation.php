<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-03 — โครงข้อมูลแกนของโมดูลสารบรรณ และการอ้างอิงกลับไปยังระบบกลาง
 *
 * หลักที่ยึดตาม SoT §5 และ §8:
 *
 *   1. **Document เป็น primary entity — PDF เป็นเพียง representation ของแต่ละเวอร์ชัน**
 *      สายงานจึงผูกกับ `document_id + document_version_id` ไม่ใช่ผูกกับไฟล์
 *   2. **ต้นฉบับห้ามถูกเขียนทับ** ทุกการแก้ไขคือเวอร์ชันใหม่เสมอ
 *   3. **ทุกการอ้างถึงคน/หน่วยงาน/ตำแหน่ง ต้องเป็น id ของ Core**
 *      `users.id`, `org_units.id`, `positions.id` — ห้ามคัดลอกทะเบียนหลักมาไว้ที่นี่
 *      และห้ามใช้ `department_id` (หน่วยงานระดับเดียว ใช้กับงานสารบรรณไม่พอ)
 *
 * เช็คพอยต์นี้วางเฉพาะ "โครงข้อมูล" — พฤติกรรมของเอกสารอยู่ที่ INT-05,
 * การออกเลขอยู่ที่ INT-06, การผูกสายงานอยู่ที่ INT-08 จึงยังไม่มีคอลัมน์ของสองเรื่องนั้น
 * ที่นี่ เพื่อไม่ให้เดาโครงสร้างล่วงหน้าแล้วต้องมาแก้ทีหลัง
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ประเภทหนังสือ — ข้อมูลอ้างอิงที่สารบรรณเป็นเจ้าของเอง (SoT §5)
         *
         * `category` แยกชนิดทะเบียนตาม SoT §9 ซึ่งเป็นตัวกำหนดชุดเลขหนังสือใน INT-06
         */
        Schema::create('saraban_document_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('abbreviation', 20)->nullable();
            $table->string('category', 30);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('saraban_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_type_id')->constrained('saraban_document_types')->restrictOnDelete();

            // ทิศทางของหนังสือ: incoming | outgoing | internal
            $table->string('direction', 20);

            $table->string('subject');
            $table->text('summary')->nullable();

            /*
             * ชั้นความลับและชั้นความเร็วตามระเบียบงานสารบรรณ
             * เก็บเป็นข้อความที่ตรวจด้วย application rule ไม่ใช่ enum ของฐานข้อมูล
             * เพื่อให้เพิ่มชั้นใหม่ได้โดยไม่ต้อง ALTER TABLE (ตามแนวทางที่ระบบเดิม
             * ใช้กับ `leaves.status` ใน `replace_leave_status_enum_with_checked_varchar`)
             */
            $table->string('confidentiality', 30)->default('normal');
            $table->string('urgency', 30)->default('normal');

            // สถานะวงจรชีวิตของตัวเอกสาร — สถานะของ "การเดินเรื่อง" อยู่ที่ workflow engine
            $table->string('status', 30)->default('draft');

            /*
             * === การอ้างอิงระบบกลาง (SoT §5) ===
             * หน่วยงานเจ้าของเรื่องต้องเป็น org_units ซึ่งเป็นต้นไม้หลายระดับ
             * และมี effective dating — ไม่ใช่ departments ที่มีระดับเดียว
             */
            $table->foreignId('owner_org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['direction', 'status']);
            $table->index(['owner_org_unit_id', 'created_at']);
            $table->index(['document_type_id', 'created_at']);
        });

        /*
         * เวอร์ชันของเอกสาร — ต้นฉบับและทุกฉบับแก้ไขอยู่คนละแถวเสมอ
         *
         * `file_hash` เก็บไว้ตั้งแต่ต้นเพราะ SoT §14 ต้องใช้เป็นหลักฐานผูกกับลายเซ็น
         * และ SoT §22 ใช้เทียบความต่างระหว่างเวอร์ชัน
         *
         * `pdf_file_path` เป็น path บน disk `private_uploads` เท่านั้น
         * การเข้าถึงต้องผ่าน `SecureFileController` ตามสัญญา INT-01 §1.12
         */
        Schema::create('saraban_document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_id')->constrained('saraban_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_no');

            // original | revised | reviewed | endorsed | signed | final
            $table->string('stage', 30)->default('original');

            $table->string('pdf_file_path')->nullable();
            $table->string('file_hash', 128)->nullable();
            $table->unsignedInteger('page_count')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'version_no']);
            $table->index(['document_id', 'stage']);
            $table->index('file_hash');
        });

        /*
         * เวอร์ชันที่ถือเป็นฉบับปัจจุบันของเอกสาร
         *
         * เพิ่มทีหลังเพราะสองตารางอ้างถึงกันและกัน — สร้างตารางเวอร์ชันให้เสร็จก่อน
         * แล้วค่อยผูก foreign key กลับ จึงไม่ต้องพึ่งการปิด foreign key check
         */
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->foreignId('current_version_id')
                ->nullable()
                ->after('status')
                ->constrained('saraban_document_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('saraban_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::dropIfExists('saraban_document_versions');
        Schema::dropIfExists('saraban_documents');
        Schema::dropIfExists('saraban_document_types');
    }
};
