<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-17 — คิวข้อผิดพลาด ธงความสามารถ และชีพจรของงานตามเวลา (SoT §19, §20, §21)
 *
 * ทั้งสามเรื่องเป็น **ความสามารถของระบบกลาง ไม่ใช่ของสารบรรณ** ตามหลักเดียวกับ
 * `TrustedClock` (INT-04), `StepUpAuthentication` (INT-11) และ `idempotency` (INT-15)
 * — โมดูลอื่นใช้ได้ทันทีโดยไม่ต้องพึ่งสารบรรณ
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * คิวข้อผิดพลาดที่ต้องมีคนตามแก้ (SoT §19)
         *
         * **ต่างจาก audit log และ error log โดยสิ้นเชิง**
         *   audit  = "เกิดอะไรขึ้น" (อ่านอย่างเดียว ห้ามแก้)
         *   log    = "ระบบพูดอะไรกับตัวเอง" (สำหรับนักพัฒนา)
         *   คิวนี้ = "อะไรค้างอยู่ที่ยังไม่มีใครแก้" (มีเจ้าของ มีสถานะ ปิดได้)
         *
         * ถ้าไม่มีตารางนี้ ข้อผิดพลาดจะกระจายอยู่ใน log ที่ไม่มีใครเปิดอ่าน
         * จนกว่าจะมีคนโทรมาถาม
         */
        Schema::create('system_exceptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // notification.dead_letter | webhook.dead_letter | saraban.number_conflict | ...
            $table->string('type', 80);
            $table->string('severity', 20)->default('warning');
            $table->string('module', 40)->nullable();

            $table->string('title', 255);
            $table->json('detail')->nullable();

            // สิ่งที่เกิดปัญหา — ใช้ morph เพื่อให้ทุกโมดูลชี้มาที่ระเบียนของตัวเองได้
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            /*
             * ปลายทางของการ "สั่งทำซ้ำ" — เก็บเป็นชนิด+id ของสิ่งที่ต้องสั่งใหม่
             * แยกจาก `subject_*` เพราะบางข้อผิดพลาดชี้ไปที่หนังสือ แต่สิ่งที่ต้อง
             * สั่งใหม่คือแถวการส่ง ไม่ใช่ตัวหนังสือ
             */
            $table->string('retry_type', 40)->nullable();
            $table->unsignedBigInteger('retry_id')->nullable();

            // open | acknowledged | assigned | resolved
            $table->string('status', 20)->default('open');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();

            /*
             * กันคิวท่วม — ปัญหาเดิมที่เกิดซ้ำต้องเป็นแถวเดียวที่นับจำนวนขึ้น
             * ไม่ใช่แถวใหม่ทุกครั้ง · ระบบที่แจ้งเตือนซ้ำพันครั้งเท่ากับไม่แจ้งเตือน
             * เพราะคนจะเลิกอ่าน
             */
            $table->string('dedupe_key', 190)->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);

            $table->string('correlation_id', 128)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique('dedupe_key', 'system_exception_dedupe_unique');
            $table->index(['status', 'severity']);
            $table->index(['type', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        /*
         * ธงความสามารถ (SoT §21)
         *
         * **Feature Flag ≠ Permission** — สิทธิ์ตอบว่า "คนนี้ทำสิ่งนี้ได้ไหม"
         * ส่วนธงตอบว่า "ความสามารถนี้เปิดอยู่ไหมสำหรับทั้งระบบ" · ธงที่เปิด
         * ไม่ได้ให้สิทธิ์ใครเพิ่ม และธงที่ปิดก็ปิดสำหรับทุกคนรวมถึงผู้ดูแลสูงสุด
         */
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('module', 40)->nullable();
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * ชีพจรของงานตามเวลา (ปิด RISK-40)
         *
         * งานที่ตั้งเวลาไว้จะ "ไม่ทำงาน" อย่างเงียบสนิทเมื่อ `schedule:run` ไม่ถูกเรียก
         * — ไม่มี error ไม่มี log ไม่มีอะไรเลย · ทางเดียวที่รู้ได้คือให้แต่ละงาน
         * ประทับเวลาไว้ แล้วมีคนเทียบว่ามันเงียบไปนานเกินกำหนดหรือยัง
         */
        Schema::create('scheduled_task_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 100)->unique();
            $table->timestamp('last_run_at');
            $table->unsignedInteger('expected_interval_minutes')->default(60);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_heartbeats');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('system_exceptions');
    }
};
