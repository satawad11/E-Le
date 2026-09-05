<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * โครงสร้างสำหรับการตรวจสอบลายเซ็น ความน่าเชื่อถือ และการเพิกถอน (SoT §14, §24)
 *
 * **สองส่วนที่แยกกันโดยเจตนา**
 *
 *   1. ช่องข้อมูลใบรับรองและ trusted timestamp เพิ่มเข้า `saraban_signature_evidences`
 *      — เป็นข้อเท็จจริง ณ ขณะลงนาม จึงต้องอยู่กับหลักฐานนั้นและ **แก้ไม่ได้**
 *      ตามที่โมเดลบังคับอยู่แล้ว
 *
 *   2. การเพิกถอนอยู่ **คนละตาราง** — เพราะการเพิกถอนเกิดขึ้นทีหลังและอาจถูก
 *      ปฏิเสธ · ถ้าเขียนลงในแถวหลักฐาน จะต้องแก้หลักฐานที่ประกาศไว้ว่าแก้ไม่ได้
 *      และจะไม่มีที่บันทึกคำขอที่ถูกปฏิเสธ
 *
 * **ทุกคอลัมน์ใบรับรองเป็น nullable** — ระบบวันนี้ทำ electronic signature ยังไม่มี
 * ใบรับรอง · ค่าว่างแปลว่า "ไม่มีใบรับรอง" ไม่ใช่ "ใบรับรองไม่ผ่าน" และหน้าจอ
 * ต้องไม่แสดงหัวข้อใบรับรองเลยเมื่อไม่มี
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saraban_signature_evidences', function (Blueprint $table) {
            /*
             * ชนิดของลายเซ็น — **แยก electronic ออกจาก digital อย่างเด็ดขาด**
             *
             * `electronic` = ลายเซ็นที่ผูกกับตัวตนที่ยืนยันแล้วและมีหลักฐานประกอบ
             * `digital` = ลงนามด้วยกุญแจส่วนตัวและมีใบรับรองรองรับ
             *
             * ค่าเริ่มต้นคือ `electronic` เพราะนั่นคือสิ่งที่ระบบทำได้จริงวันนี้
             * การเลื่อนขึ้นเป็น `digital` ต้องมาพร้อมข้อมูลใบรับรองจริงเท่านั้น
             */
            $table->string('signature_type', 20)->default('electronic')->after('signature_method');

            /*
             * ลำดับที่คาดหวังของการลงนามฉบับนี้ — เริ่มที่ 1
             *
             * เก็บ ณ เวลาลงนามเพราะเป็นข้อเท็จจริงของเหตุการณ์นั้น · ใช้ตรวจว่า
             * ลำดับเวลาจริงตรงกับลำดับที่ควรเป็นหรือไม่ (เช่น หัวหน้าลงนามก่อน
             * ผู้ตรวจทาน ซึ่งผิดขั้นตอน)
             */
            $table->unsignedInteger('signing_sequence')->nullable()->after('signature_type');

            // ── ใบรับรอง (ยังไม่มีการใช้งานจริง — เตรียมโครงไว้) ──
            $table->string('certificate_serial', 128)->nullable();
            $table->string('certificate_subject')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('certificate_fingerprint', 128)->nullable();
            $table->timestamp('certificate_valid_from')->nullable();
            $table->timestamp('certificate_valid_to')->nullable();
            /*
             * เวลาที่ใบรับรองถูกเพิกถอน — มาจากผู้ออกใบรับรอง ไม่ใช่ระบบนี้
             *
             * ใบรับรองที่ถูกเพิกถอน **หลัง** วันที่ลงนาม ไม่ทำให้ลายเซ็นเดิมเสีย
             * ถ้ามี trusted timestamp ยืนยันว่าลงนามก่อนการเพิกถอนจริง — ตรรกะนี้
             * อยู่ในบริการตรวจสอบ ไม่ใช่ในโครงสร้างตาราง
             */
            $table->timestamp('certificate_revoked_at')->nullable();

            // ── trusted timestamp จากผู้ให้บริการภายนอก (TSA) ──
            $table->string('timestamp_authority')->nullable();
            $table->string('timestamp_token_hash', 128)->nullable();
            $table->timestamp('timestamped_at')->nullable();

            $table->index('certificate_serial');
        });

        /**
         * ทะเบียนคำขอเพิกถอนลายเซ็น/หนังสือ
         *
         * **คำขอที่ถูกปฏิเสธยังอยู่ในทะเบียน** — ต้องตอบได้ว่าเคยมีคนขอเพิกถอน
         * หนังสือฉบับนี้เมื่อใดด้วยเหตุผลใด และใครเป็นผู้ปฏิเสธ
         */
        Schema::create('saraban_signature_revocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_id')->constrained('saraban_documents')->restrictOnDelete();
            /*
             * ระบุหลักฐานได้ = เพิกถอนลายเซ็นรายฉบับ · ปล่อยว่าง = เพิกถอนทั้งหนังสือ
             *
             * งานราชการมีทั้งสองกรณีจริง: ลงนามผิดคนหนึ่งราย กับหนังสือทั้งฉบับ
             * ที่ออกไปโดยไม่ถูกต้อง
             */
            $table->foreignId('signature_evidence_id')->nullable()
                ->constrained('saraban_signature_evidences')->restrictOnDelete();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            // เหตุผลบังคับเสมอ — การเพิกถอนที่ไม่มีเหตุผลคือการลบร่องรอย
            $table->text('reason');

            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_signature_revocations');

        Schema::table('saraban_signature_evidences', function (Blueprint $table) {
            $table->dropIndex(['certificate_serial']);
            $table->dropColumn([
                'signature_type', 'signing_sequence',
                'certificate_serial', 'certificate_subject', 'certificate_issuer',
                'certificate_fingerprint', 'certificate_valid_from', 'certificate_valid_to',
                'certificate_revoked_at',
                'timestamp_authority', 'timestamp_token_hash', 'timestamped_at',
            ]);
        });
    }
};
