<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-06 · 06-1 — Time Foundation (additive ทั้งหมด)
 *
 * ปัญหาที่แก้ (TIME-SCH-001): เวลาทำงานเก็บเป็น "ค่าเดี่ยวต่อคน"
 * (`users.work_start_time` / `work_end_time`) พร้อม fallback ไปที่ setting
 * `default_work_start_time` ผลคือ:
 *
 *   - ไม่มีตารางรายวัน — เสาร์อาทิตย์ใช้เวลาเดียวกับวันธรรมดา
 *   - ไม่มีกะ และไม่รองรับกะข้ามวัน
 *   - ไม่มี effective date — แก้เวลาทำงานวันนี้ = เปลี่ยนประวัติการมาสายย้อนหลัง
 *   - ไม่มี grace period — สายทันทีที่เกินเวลาเริ่มงาน 1 วินาที
 *   - ตารางของหน่วยงานกับของรายบุคคลแยกกันไม่ได้
 *
 * สิ่งที่ **ไม่** สร้างใหม่เพราะของเดิมดีอยู่แล้ว (TIME-CAL-001 = PASS):
 *   - `holidays` — วันหยุดราชการ
 *   - `work_calendar_exceptions` — วันหยุด/วันทำงานชดเชยที่ระบุรายหน่วยงานได้
 *   - `fiscal_years` — ปีงบประมาณ
 *
 * ทั้งสามตารางถูก **เชื่อมเข้ากับ engine** ผ่าน `WorkingCalendar` (06-4)
 * ไม่ใช่สร้างซ้ำ
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ตารางเวลาทำงานหนึ่งชุด เช่น "เวลาราชการปกติ" หรือ "กะดึก"
         *
         * timezone เก็บที่นี่เพื่อให้หน่วยงานที่อยู่คนละเขตเวลาใช้ตารางของตัวเองได้
         * โดยไม่ต้องแก้โค้ด — ค่าเริ่มต้นคือ Asia/Bangkok ตามที่ระบบใช้
         */
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('timezone', 64)->default('Asia/Bangkok');

            /*
             * ผ่อนผันการมาสาย — 06-3
             * ของเดิมถือว่าสายทันทีที่เกินเวลาเริ่มงาน 1 วินาที
             */
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(0);

            // ตารางที่ใช้เมื่อไม่มีการมอบหมายใดตรงกับผู้ใช้เลย
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_default', 'is_active']);
        });

        /*
         * เวลาทำงานรายวันของตาราง — หนึ่งแถวต่อหนึ่งวันในสัปดาห์
         *
         * `day_of_week` ใช้เลขเดียวกับ Carbon: 0 = อาทิตย์ ... 6 = เสาร์
         * เพื่อไม่ให้ต้องแปลงเลขไปมาแล้วผิดพลาด
         *
         * กะข้ามวัน: `end_time` น้อยกว่าหรือเท่ากับ `start_time` แปลว่ากะจบวันถัดไป
         * เก็บ `crosses_midnight` ไว้ชัดเจนเพื่อไม่ต้องเดาจากการเทียบเวลา
         */
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('crosses_midnight')->default(false);
            $table->timestamps();

            $table->unique(['work_schedule_id', 'day_of_week'], 'work_schedule_day_unique');
        });

        /*
         * การมอบหมายตารางเวลา — effective-dated ตาม PROJECT_RULES ข้อ 7
         *
         * ลำดับความสำคัญตอนหาตารางของคนหนึ่งในวันหนึ่ง:
         *   1. มอบหมายให้ผู้ใช้คนนั้นโดยตรง  (person-specific override)
         *   2. มอบหมายให้หน่วยงาน — ไล่จากหน่วยงานของตนขึ้นไปหาหน่วยงานแม่
         *   3. มอบหมายระดับองค์กร (ทั้ง user_id และ org_unit_id เป็น null)
         *   4. ตารางที่ตั้ง `is_default`
         *
         * ห้ามลบแถวเก่าเพื่อแก้ข้อมูลย้อนหลัง — ให้ปิดด้วย `effective_to`
         * แล้วออกแถวใหม่ มิฉะนั้นประวัติการมาสายย้อนหลังจะเปลี่ยนตาม
         */
        Schema::create('work_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'effective_from', 'effective_to'], 'work_schedule_user_effective');
            $table->index(['org_unit_id', 'effective_from', 'effective_to'], 'work_schedule_org_effective');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_assignments');
        Schema::dropIfExists('work_schedule_days');
        Schema::dropIfExists('work_schedules');
    }
};
