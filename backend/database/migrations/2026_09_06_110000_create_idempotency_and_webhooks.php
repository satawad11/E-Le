<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INT-15 — กุญแจกันทำซ้ำ และ webhook ขาออก (SoT §17)
 *
 * ทั้งสองเรื่องเป็น **ความสามารถของระบบกลาง ไม่ใช่ของสารบรรณ** ตามหลักเดียวกับ
 * `TrustedClock` และ `StepUpAuthentication` ที่เช็คพอยต์ก่อนหน้าวางไว้ — โมดูลอื่น
 * ใช้ได้ทันทีโดยไม่ต้องพึ่งสารบรรณ
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * SEC-GAP-04 — กันการทำซ้ำจากคำขอเดิม (SoT §17)
         *
         * ปัญหาจริงที่ปิด: ผู้ใช้กดปุ่ม "ออกเลขหนังสือ" สองครั้งเพราะเน็ตช้า หรือ
         * ระบบภายนอกส่งซ้ำหลัง timeout — ทั้งสองกรณีทำให้เกิดเลขหนังสือสองเลข
         * สำหรับเรื่องเดียว ซึ่งเป็นความเสียหายที่แก้ย้อนหลังไม่ได้ในงานสารบรรณ
         */
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 190);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('method', 10);
            $table->string('path', 255);

            /*
             * ลายนิ้วมือของ *เนื้อคำขอ* — กุญแจเดิมกับเนื้อหาต่างกันคือความผิดพลาด
             * ของผู้เรียก ไม่ใช่การส่งซ้ำ · ถ้าไม่เทียบ ระบบจะคืนผลของคำขอเก่า
             * ให้คำขอใหม่ที่ผู้เรียกตั้งใจส่งจริง ๆ
             */
            $table->string('request_hash', 64);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();

            // ยังทำงานอยู่ = ยังไม่มี completed_at · ใช้กันคำขอซ้อนที่ยิงมาพร้อมกัน
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            /*
             * กุญแจเป็นของ *ผู้เรียกคนนั้น* ไม่ใช่ของทั้งระบบ — ถ้า unique เฉพาะ
             * ตัวกุญแจ ผู้ใช้คนหนึ่งจะทำให้คำขอของอีกคนถูกปฏิเสธได้ด้วยการเดากุญแจ
             */
            $table->unique(['idempotency_key', 'user_id'], 'idempotency_key_per_user');
            $table->index('expires_at');
        });

        /*
         * webhook ขาออก (SoT §17)
         *
         * แยก "ปลายทาง" ออกจาก "การส่งแต่ละครั้ง" ด้วยเหตุผลเดียวกับที่ INT-13
         * แยกการเวียนหนังสือออกจากผู้รับ — ต้องตอบได้ว่าปลายทางหนึ่งได้รับอะไรบ้าง
         * และแต่ละครั้งสำเร็จหรือไม่
         */
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('url', 500);

            /*
             * ความลับสำหรับเซ็น payload ด้วย HMAC — ปลายทางใช้ตรวจว่าข้อความ
             * มาจากระบบนี้จริง · ไม่คืนออกทาง API หลังสร้างแล้ว
             */
            $table->string('secret', 100);

            // รายชื่อเหตุการณ์ที่ปลายทางนี้สนใจ · ว่าง = ไม่รับอะไรเลย ไม่ใช่รับทุกอย่าง
            $table->json('events');

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();

            $table->string('event', 120);
            $table->json('payload');

            // pending | sending | sent | retrying | failed
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // ใช้กติกาเดียวกับ dead-letter ของศูนย์แจ้งเตือน (INT-14) ไม่คิดกลไกใหม่
            $table->timestamp('dead_lettered_at')->nullable();

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('dead_lettered_at');
            $table->index(['webhook_endpoint_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('idempotency_keys');
    }
};
