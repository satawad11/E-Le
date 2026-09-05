<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-06 — เครื่องออกเลขหนังสือ (SoT §9)
 *
 * โครงสร้างแยกเป็นสามส่วนโดยตั้งใจ:
 *
 *   `saraban_number_series`     ชุดเลข — ตั้งค่าว่าหน่วยงานใด ประเภทใด ใช้คำนำหน้าอะไร
 *   `saraban_number_sequences`  ตัวนับของชุดเลขนั้นในแต่ละปี พ.ศ. (รีเซ็ตทุกปี)
 *   `saraban_document_numbers`  เลขแต่ละเลขพร้อมสถานะและประวัติ
 *
 * **เหตุผลที่ต้องมีตารางตัวนับแยก:** SoT §9 ห้าม `SELECT MAX()+1` แบบไม่มี lock
 * การมีแถวตัวนับแถวเดียวต่อ (ชุดเลข, ปี) ทำให้ล็อกได้ตรง ๆ ด้วย `lockForUpdate()`
 * โดยไม่ต้องล็อกทั้งตารางเลข และเป็นรูปแบบเดียวกับ `LeaveDocumentNumberService`
 * ที่ระบบเดิมใช้อยู่แล้ว
 *
 * unique `(series, ปี, เลขที่)` เป็นด่านสุดท้าย — ต่อให้ตรรกะพลาด ฐานข้อมูลจะไม่ยอม
 * ให้มีเลขซ้ำในชุดเดียวกันของปีเดียวกันเด็ดขาด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saraban_number_series', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 80)->unique();
            $table->string('name');

            // incoming | outgoing | internal | order | announcement (ตรงกับประเภทหนังสือ)
            $table->string('category', 30);

            /*
             * null = ชุดเลขระดับองค์กร · มีค่า = ชุดเลขเฉพาะหน่วยงานนั้น
             * อ้าง org_units ตามสัญญา INT-01 §1.3 (ห้ามใช้ department_id)
             */
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();

            // คำนำหน้าตามระเบียบงานสารบรรณ เช่น "ศธ 04001"
            $table->string('prefix', 80)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'org_unit_id', 'is_active'], 'saraban_series_lookup');
        });

        /*
         * ตัวนับรายปี — หนึ่งแถวต่อ (ชุดเลข, ปี พ.ศ.) เพื่อให้ล็อกได้แถวเดียว
         * การขึ้นปีใหม่ = แถวใหม่ที่เริ่มจากศูนย์ จึงได้ annual reset ตาม SoT §9 เอง
         */
        Schema::create('saraban_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('number_series_id')->constrained('saraban_number_series')->cascadeOnDelete();
            $table->unsignedSmallInteger('buddhist_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['number_series_id', 'buddhist_year'], 'saraban_sequence_year_unique');
        });

        Schema::create('saraban_document_numbers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('number_series_id')->constrained('saraban_number_series')->restrictOnDelete();
            $table->unsignedSmallInteger('buddhist_year');
            $table->unsignedInteger('running_number');

            // เลขที่แสดงจริง เช่น "ศธ 04001/0007"
            $table->string('formatted', 120);

            // reserved | allocated | issued | released | cancelled | void
            $table->string('status', 20);

            $table->foreignId('document_id')->nullable()->constrained('saraban_documents')->nullOnDelete();

            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('reason')->nullable();

            $table->timestamps();

            // ด่านสุดท้ายกันเลขซ้ำ — ต่อให้ตรรกะพลาดก็ยังซ้ำไม่ได้
            $table->unique(['number_series_id', 'buddhist_year', 'running_number'], 'saraban_number_unique');
            $table->index(['status', 'number_series_id', 'buddhist_year'], 'saraban_number_status_lookup');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saraban_document_numbers');
        Schema::dropIfExists('saraban_number_sequences');
        Schema::dropIfExists('saraban_number_series');
    }
};
