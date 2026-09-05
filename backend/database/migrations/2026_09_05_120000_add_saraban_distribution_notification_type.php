<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INT-13 — ชนิดการแจ้งเตือนของการเวียนหนังสือ (SoT §16)
 *
 * **REUSE ศูนย์แจ้งเตือนกลาง** ตามที่ SoT §16 กำหนด — สารบรรณไม่สร้างชั้น
 * แจ้งเตือนของตัวเอง จึงลงทะเบียนเป็น `notification_types` ตามแบบเดียวกับ
 * `attendance.leave_conflict` ที่ระบบมีอยู่แล้ว
 *
 * ช่องทางเริ่มต้นเป็น `in_app` เท่านั้น เพราะ **หัวเรื่องหนังสือราชการอาจเป็น
 * ข้อมูลชั้นความลับ** การส่งออกนอกระบบโดยอัตโนมัติต้องเป็นการเลือกของผู้ใช้
 * ไม่ใช่ค่าตั้งต้น · ผู้ใช้เปิดช่องทางอื่นเองได้จากหน้าตั้งค่าการแจ้งเตือนเดิม
 */
return new class extends Migration
{
    private const CODE = 'saraban.document.distributed';

    public function up(): void
    {
        if (DB::table('notification_types')->where('code', self::CODE)->exists()) {
            return;
        }

        $now = now();
        $name = 'มีหนังสือเวียนถึงท่าน';
        $body = 'มีหนังสือราชการเวียนถึงท่าน กรุณาเปิดกล่องหนังสือเข้าเพื่อรับทราบ';

        $typeId = DB::table('notification_types')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => self::CODE,
            'module' => 'saraban',
            'name' => $name,
            'default_channels' => json_encode(['in_app']),
            'is_mandatory' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['in_app', 'email', 'line_oa', 'telegram'] as $channel) {
            DB::table('notification_templates')->insert([
                'uuid' => (string) Str::uuid(),
                'notification_type_id' => $typeId,
                'channel' => $channel,
                'version' => 1,
                'locale' => 'th',
                'subject' => $name,
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
        $typeId = DB::table('notification_types')->where('code', self::CODE)->value('id');

        if ($typeId === null) {
            return;
        }

        DB::table('notification_templates')->where('notification_type_id', $typeId)->delete();
        DB::table('notification_types')->where('id', $typeId)->delete();
    }
};
