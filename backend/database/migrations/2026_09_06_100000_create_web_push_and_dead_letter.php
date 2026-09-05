<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * INT-14 — ช่องทาง Web Push และ dead-letter ของศูนย์แจ้งเตือน (SoT §16)
 *
 * **ต่อยอดศูนย์แจ้งเตือนกลางที่มีอยู่แล้ว ไม่สร้างชั้นแจ้งเตือนของสารบรรณเอง**
 * — ตาราง `notification_*` ทั้งหมดยังเป็นของระบบกลาง สารบรรณเป็นเพียงผู้ส่ง
 * เหตุการณ์เข้าไปเหมือนโมดูลอื่น
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * การสมัครรับ Web Push — หนึ่งแถวต่อหนึ่ง "เบราว์เซอร์บนหนึ่งเครื่อง"
         * ไม่ใช่หนึ่งแถวต่อหนึ่งคน · คนหนึ่งใช้ทั้งเครื่องที่ทำงานและมือถือได้
         */
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * endpoint คือที่อยู่ของ push service ของเบราว์เซอร์นั้น ๆ
             * ยาวได้หลายร้อยตัวอักษร · unique เพื่อกันการสมัครซ้ำเมื่อผู้ใช้
             * กดอนุญาตหลายครั้งบนเครื่องเดิม
             */
            $table->string('endpoint', 500)->unique();

            // กุญแจสาธารณะและ auth secret ของเบราว์เซอร์ ใช้เข้ารหัส payload
            $table->string('p256dh_key', 255);
            $table->string('auth_token', 255);

            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        /*
         * dead-letter (SoT §16)
         *
         * เดิมข้อความที่ส่งไม่สำเร็จจนครบจำนวนครั้งจะค้างอยู่ที่สถานะ `failed`
         * เฉย ๆ ไม่มีใครรู้ว่ามีอยู่ และไม่มีทางสั่งส่งใหม่ · คอลัมน์นี้ทำให้
         * "ข้อความที่ตายแล้ว" เป็นสิ่งที่ค้นเจอ นับได้ และสั่งกู้ได้
         */
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->timestamp('dead_lettered_at')->nullable()->after('sent_at');
            $table->index('dead_lettered_at');
        });

        // ข้อความที่ล้มเหลวไปแล้วก่อนมีคอลัมน์นี้ ต้องปรากฏใน dead-letter ด้วย
        DB::table('notification_deliveries')
            ->where('status', 'failed')
            ->whereNull('dead_lettered_at')
            ->update(['dead_lettered_at' => DB::raw('COALESCE(last_attempt_at, updated_at)')]);

        $this->seedWebPushTemplates();
    }

    /**
     * ทุกชนิดการแจ้งเตือนต้องมี template ของช่องทางใหม่
     *
     * ถ้าไม่มี `NotificationDeliveryService` จะตีเป็น `skipped` พร้อมข้อความ
     * "No active template configured" — ผู้ใช้เปิดช่องทางแล้วไม่ได้รับอะไรเลย
     * โดยไม่มีใครรู้ว่าเพราะอะไร
     */
    private function seedWebPushTemplates(): void
    {
        $now = now();

        $types = DB::table('notification_types')->get(['id', 'name']);

        foreach ($types as $type) {
            $exists = DB::table('notification_templates')
                ->where('notification_type_id', $type->id)
                ->where('channel', 'web_push')
                ->exists();

            if ($exists) {
                continue;
            }

            $body = DB::table('notification_templates')
                ->where('notification_type_id', $type->id)
                ->where('channel', 'in_app')
                ->value('body_template') ?? $type->name;

            DB::table('notification_templates')->insert([
                'uuid' => (string) Str::uuid(),
                'notification_type_id' => $type->id,
                'channel' => 'web_push',
                'version' => 1,
                'locale' => 'th',
                'subject' => $type->name,
                'body_template' => $body,
                'effective_from' => $now->toDateString(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('channel', 'web_push')->delete();

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['dead_lettered_at']);
            $table->dropColumn('dead_lettered_at');
        });

        Schema::dropIfExists('web_push_subscriptions');
    }
};
