<?php

use App\Models\Saraban\NumberSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-07 — ทะเบียนรับหนังสือ (SoT §10)
 *
 * **สิ่งที่สำคัญที่สุดของตารางนี้คือการแยกสองชุดข้อมูลออกจากกัน:**
 *
 *   ข้อมูลของ *หนังสือต้นทาง*   `source_number`, `source_date`, `sender_name`
 *                              — เลขและวันที่ที่ผู้ส่งเป็นคนออก ระบบเราไม่ได้ควบคุม
 *
 *   ข้อมูลของ *การรับ*          `document_number_id`, `received_at`
 *                              — เลขทะเบียนรับและวันเวลาที่รับ ซึ่งเป็นของเรา
 *
 * ถ้าเก็บปนกันจะตอบไม่ได้ว่า "หนังสือ ศธ 04002/123 ลงวันที่ 1 ก.ย." กับ
 * "ทะเบียนรับเลข 45 เมื่อ 3 ก.ย." คือเลขคนละชุดกัน ซึ่งเป็นคนละความหมาย
 * ตามระเบียบงานสารบรรณ และเป็นที่มาของการตรวจสอบย้อนหลังไม่ได้
 *
 * ดัชนีบน (เลขต้นทาง, ผู้ส่ง, วันที่ต้นทาง) มีไว้ตรวจหนังสือซ้ำก่อนลงทะเบียน
 * ตาม flow ของ SoT §10 — ไม่ใช่ unique constraint เพราะการรับซ้ำอาจถูกต้องได้
 * (เช่น ผู้ส่งส่งซ้ำจริงและหน่วยงานตัดสินใจรับทั้งสองฉบับ) จึงต้องเป็นการ
 * "เตือนแล้วให้คนตัดสิน" ไม่ใช่ "ฐานข้อมูลปฏิเสธ"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_incoming_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // หนังสือหนึ่งฉบับลงทะเบียนรับได้ครั้งเดียว
            $table->foreignId('document_id')->unique()->constrained('saraban_documents')->cascadeOnDelete();

            // ── ข้อมูลของหนังสือต้นทาง (ผู้ส่งเป็นคนออก) ──
            $table->string('source_number', 120)->nullable();
            $table->date('source_date')->nullable();
            $table->string('sender_name');

            // ── ข้อมูลของการรับ (ระบบเราเป็นคนออก) ──
            $table->foreignId('document_number_id')->nullable()->constrained('saraban_document_numbers')->nullOnDelete();
            $table->timestamp('received_at');

            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['source_number', 'sender_name', 'source_date'], 'saraban_incoming_duplicate_lookup');
            $table->index('received_at');
        });

        /*
         * ชุดเลขทะเบียนรับตั้งต้น
         *
         * เลขทะเบียนรับเป็นเลขลำดับภายในของหน่วยงาน ไม่มีคำนำหน้าตามหนังสือราชการ
         * (ต่างจากเลขหนังสือส่งที่ต้องมี "ศธ ....") จึงตั้งค่าให้ได้ตั้งแต่ต้น
         * โดยไม่ต้องเดาค่าเฉพาะของสถานศึกษา — ผู้ดูแลแก้ชื่อ/คำนำหน้าได้ภายหลัง
         */
        NumberSeries::firstOrCreate(
            ['code' => 'incoming-register'],
            [
                'name' => 'ทะเบียนรับหนังสือ',
                'category' => 'incoming',
                'prefix' => null,
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_incoming_registrations');

        NumberSeries::query()->where('code', 'incoming-register')->delete();
    }
};
