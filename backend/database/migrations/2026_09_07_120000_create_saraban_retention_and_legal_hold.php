<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-19 — การเก็บรักษา การอายัด และการทำลายเอกสาร (SoT §25)
 *
 * **ข้อบังคับที่หนักที่สุดของ SoT §25: หนังสือที่ออกไปแล้วห้ามลบตามปกติ**
 *
 * ระบบเดิมไม่มีเส้นทางลบหนังสืออยู่แล้ว (ไม่มี `destroy` ใน `DocumentController`)
 * แต่ "ไม่มีปุ่ม" ไม่เท่ากับ "ลบไม่ได้" — วันหนึ่งจะมีคนเพิ่มปุ่มนั้นเข้ามา
 * เช็คพอยต์นี้จึงเปลี่ยนจาก "ยังไม่ได้ทำ" เป็น **"ห้ามทำ และมีทางที่ถูกต้องแทน"**
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * การอายัดเอกสาร (Legal Hold — SoT §25)
         *
         * เมื่อมีคดีความหรือการสอบสวน เอกสารที่เกี่ยวข้องต้องถูกแช่ไว้
         * **แม้จะครบกำหนดทำลายแล้วก็ตาม** — การอายัดชนะกำหนดการทำลายเสมอ
         *
         * แถวที่ปลดแล้วยังอยู่ ไม่ถูกลบ เพราะต้องตอบได้ว่าเอกสารเคยถูกอายัด
         * ในช่วงใดและด้วยเหตุใด
         */
        Schema::create('saraban_legal_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained('saraban_documents')->cascadeOnDelete();

            $table->string('reference', 120);
            $table->string('reason', 500);

            $table->timestamp('placed_at');
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_note', 500)->nullable();

            $table->timestamps();

            $table->index(['document_id', 'released_at']);
        });

        /*
         * กำหนดการเก็บรักษาและการทำลาย (SoT §25)
         *
         * งานสารบรรณราชการมีระเบียบว่าเอกสารแต่ละประเภทเก็บกี่ปี · ตารางนี้บันทึก
         * **กำหนดของเอกสารแต่ละฉบับ** ไม่ใช่กติกากลาง (กติกากลางอยู่ในศูนย์นโยบาย)
         * เพราะเอกสารฉบับหนึ่งอาจถูกขยายเวลาเก็บเป็นรายฉบับ
         *
         * `disposed_at` มีค่าแปลว่า **ทำลายไปแล้ว** — แต่แถวทะเบียนยังอยู่ครบ
         * เพราะการทำลายเอกสารต้องตอบได้ว่าเคยมีเอกสารฉบับใดและถูกทำลายเมื่อใด
         * โดยใคร · ทะเบียนที่ลบตัวเองพร้อมเอกสารคือทะเบียนที่ใช้ตรวจสอบไม่ได้
         */
        Schema::create('saraban_retention_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained('saraban_documents')->cascadeOnDelete()->unique();

            $table->unsignedSmallInteger('retain_years');
            $table->date('retain_until');

            // proposed | approved | disposed — ทำลายต้องผ่านการอนุมัติเสมอ
            $table->string('status', 20)->default('proposed');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('disposed_at')->nullable();
            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disposal_note', 500)->nullable();

            /*
             * ลายนิ้วมือของไฟล์ ณ เวลาที่ทำลาย — หลังทำลายไฟล์แล้ว นี่คือสิ่งเดียว
             * ที่พิสูจน์ได้ว่าไฟล์ที่ถูกทำลายคือไฟล์ไหน
             */
            $table->string('disposed_file_hash', 128)->nullable();

            $table->timestamps();

            $table->index(['status', 'retain_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_retention_schedules');
        Schema::dropIfExists('saraban_legal_holds');
    }
};
