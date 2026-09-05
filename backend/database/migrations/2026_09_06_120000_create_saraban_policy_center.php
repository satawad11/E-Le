<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-16 — ศูนย์นโยบายแบบมีเวอร์ชัน (SoT §18)
 *
 * **แยก "นโยบาย" ออกจาก "การตั้งค่า" โดยตั้งใจ**
 *
 * ตาราง `settings` เดิมของระบบเก็บค่าปัจจุบันอย่างเดียว เขียนทับได้ ไม่มีประวัติ
 * ซึ่งพอสำหรับชื่อสถานศึกษาหรือโลโก้ แต่ไม่พอสำหรับกติกาที่ใช้ตัดสินเอกสารราชการ
 *
 * หนังสือที่ออกเมื่อวานถูกตัดสินด้วยกติกาของเมื่อวาน ไม่ใช่กติกาวันนี้ — ถ้าตอบ
 * ไม่ได้ว่า "ตอนนั้นกติกาเป็นอย่างไร" ก็ตรวจสอบย้อนหลังไม่ได้เลย · SoT §18
 * จึงบังคับ versioned / published / rollback / audit ครบทั้งสี่ข้อ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ชื่อเชิงระบบ เช่น `numbering`, `distribution`, `document_layout`
            $table->string('key', 60)->unique();

            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('saraban_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('policy_id')->constrained('saraban_policies')->cascadeOnDelete();

            $table->unsignedInteger('version_no');

            // draft = แก้ได้ · published = ใช้งานอยู่ · archived = เคยใช้ ไม่ใช้แล้ว
            $table->string('status', 20)->default('draft');

            $table->json('payload');
            $table->string('notes', 500)->nullable();

            $table->timestamp('effective_from')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            /*
             * ประวัติเป็นแบบ append-only — เวอร์ชันเดิมไม่ถูกแก้หรือลบ
             * การ "ย้อนกลับ" คือการเผยแพร่เวอร์ชัน **ใหม่** ที่มีเนื้อหาเหมือนของเก่า
             * ไม่ใช่การปลุกเวอร์ชันเก่าขึ้นมาใหม่ ซึ่งจะทำให้ไทม์ไลน์ขาดตอน
             */
            $table->unique(['policy_id', 'version_no'], 'saraban_policy_version_unique');
            $table->index(['policy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_policy_versions');
        Schema::dropIfExists('saraban_policies');
    }
};
