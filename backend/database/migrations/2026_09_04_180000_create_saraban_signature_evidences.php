<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-11 — หลักฐานการลงนาม (SoT §14)
 *
 * SoT §14 กำหนดสิ่งที่ต้องเก็บไว้เป็นหลักฐานของการลงนามแต่ละครั้ง:
 * ผู้ลงนาม / ตำแหน่ง / อำนาจ / session / เวลา / วิธี / เวอร์ชัน / hash / IP / UA / audit
 *
 * **เก็บเป็น snapshot ไม่ใช่ join** — ชื่อและตำแหน่งของผู้ลงนาม ณ เวลาที่ลงนาม
 * ต้องไม่เปลี่ยนตามเมื่อเขาย้ายหน่วยงานหรือเปลี่ยนตำแหน่งในภายหลัง หนังสือที่
 * ลงนามไปแล้วต้องบอกได้เสมอว่า "ใครลงนามในฐานะอะไร ณ วันนั้น"
 *
 * แต่ยังเก็บ `signer_id` ควบคู่เสมอ เพื่อให้อ้างกลับไปยังบุคคลจริงได้
 * ตามสัญญา INT-01 §1.2
 *
 * `document_hash` ผูกลายเซ็นกับ **เนื้อไฟล์ฉบับที่ลงนาม** — ถ้าไฟล์ถูกเปลี่ยน
 * ภายหลัง แฮชจะไม่ตรงและตรวจพบได้ทันที
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_signature_evidences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_id')->constrained('saraban_documents')->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained('saraban_document_versions')->restrictOnDelete();

            // ── ผู้ลงนาม ──
            $table->foreignId('signer_id')->constrained('users')->restrictOnDelete();
            $table->string('signer_name_snapshot');
            $table->string('position_snapshot')->nullable();
            $table->string('org_unit_snapshot')->nullable();
            $table->string('authority_snapshot')->nullable();

            // ── การลงนาม ──
            $table->timestamp('signed_at');
            // drawn = วาดสด · uploaded = ใช้ไฟล์ลายเซ็นที่บันทึกไว้
            $table->string('signature_method', 20);
            $table->string('document_hash', 128);

            // ── หลักฐานของ session (SoT §14, §24) ──
            $table->unsignedBigInteger('auth_token_id')->nullable();
            $table->timestamp('reauthenticated_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'signed_at']);
            $table->index('document_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_signature_evidences');
    }
};
