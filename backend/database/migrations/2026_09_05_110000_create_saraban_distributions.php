<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-13 — การกระจายหนังสือและกล่องหนังสือเข้า (SoT §15)
 *
 * **แยกสองชั้นโดยตั้งใจ**
 *
 *   `saraban_distributions`            = การส่งหนึ่งครั้ง (ใคร ส่งฉบับไหน เมื่อไร)
 *   `saraban_distribution_recipients`  = ผู้รับแต่ละคนและสถานะของคนนั้น
 *
 * ถ้ารวมเป็นตารางเดียว จะตอบไม่ได้ว่า "หนังสือฉบับนี้เวียนไปกี่ครั้ง" ซึ่งเป็น
 * คำถามปกติของงานสารบรรณเมื่อมีการเวียนซ้ำถึงคนกลุ่มใหม่
 *
 * **ผูกกับ `document_version_id` ด้วย** ตาม SoT §8 — ผู้รับต้องบอกได้เสมอว่า
 * ตนได้รับหนังสือ *ฉบับไหน* ไม่ใช่แค่ได้รับ "หนังสือเรื่องนี้"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_distributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_id')->constrained('saraban_documents')->cascadeOnDelete();

            /*
             * ฉบับที่ส่งออกไป — restrict ไม่ใช่ cascade เพราะการลบเวอร์ชันที่มีคน
             * ได้รับไปแล้วคือการทำลายหลักฐานว่าเขาได้รับอะไร
             */
            $table->foreignId('document_version_id')->constrained('saraban_document_versions')->restrictOnDelete();

            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at');

            // คำสั่งการหรือหมายเหตุถึงผู้รับ เช่น "เพื่อทราบ" หรือ "เพื่อพิจารณาดำเนินการ"
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['document_id', 'sent_at']);
        });

        Schema::create('saraban_distribution_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('distribution_id')->constrained('saraban_distributions')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();

            // to = ผู้รับปฏิบัติ · cc = สำเนาเรียน
            $table->string('recipient_role', 10);

            /*
             * ผู้ส่งเลือกเป้าหมายเป็นอะไร (user | org_unit | org_subtree | all_authorized)
             * เก็บไว้เพื่อให้ตอบได้ว่า "คนนี้ได้รับเพราะอะไร" ไม่ใช่แค่ว่าได้รับ
             */
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id')->nullable();

            /*
             * Recipient Snapshot — **บังคับตาม SoT §15**
             *
             * เก็บเป็นข้อความ ไม่ใช่ join เพราะทะเบียนต้องบอกได้ตลอดไปว่าหนังสือ
             * ส่งถึงใครในฐานะอะไร ณ วันที่ส่ง แม้ผู้รับจะย้ายหน่วยงานหรือเปลี่ยน
             * ตำแหน่งไปแล้วก็ตาม
             */
            $table->string('recipient_name_snapshot');
            $table->string('position_snapshot')->nullable();
            $table->string('org_unit_snapshot')->nullable();

            /*
             * สถานะตาม SoT §15
             * delivered → opened → acknowledged → accepted_for_action → in_progress → completed
             */
            $table->string('status', 30)->default('delivered');

            /*
             * เวลาของแต่ละขั้นแยกคอลัมน์ ไม่ใช่เก็บแค่สถานะปัจจุบัน
             *
             * **`opened_at` ไม่ใช่ `acknowledged_at`** (SoT §15) — การเปิดอ่านคือ
             * ข้อเท็จจริงทางเทคนิค ส่วนการรับทราบคือการแสดงเจตนาซึ่งใช้อ้างอิงได้
             * ทางราชการ ระบบจึงต้องแยกสองอย่างนี้ออกจากกันอย่างเด็ดขาด
             */
            $table->timestamp('delivered_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // ความเห็นของผู้รับตอนรับทราบหรือรายงานผล
            $table->string('response_note', 500)->nullable();

            $table->timestamps();

            /*
             * คนเดียวกันได้รับจากการส่งครั้งเดียวกันซ้ำไม่ได้
             *
             * ตั้งชื่อ index เองเพราะชื่อที่ Laravel สร้างให้จากชื่อตารางและคอลัมน์
             * ยาว 66 ตัวอักษร เกินเพดาน 64 ตัวของ MySQL — SQLite ในชุดทดสอบไม่มี
             * เพดานนี้ ความผิดพลาดจึงโผล่ตอน migrate บนฐานจริงเท่านั้น
             */
            $table->unique(['distribution_id', 'recipient_id'], 'saraban_distribution_recipient_unique');

            // กล่องหนังสือเข้าของแต่ละคน เรียงตามเวลาที่ได้รับ
            $table->index(['recipient_id', 'status']);
            $table->index(['recipient_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        // ลบลูกก่อนพ่อ เพื่อไม่ต้องปิด foreign key constraint
        Schema::dropIfExists('saraban_distribution_recipients');
        Schema::dropIfExists('saraban_distributions');
    }
};
