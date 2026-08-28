<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * แจ้งเตือนเมื่อมีการลงเวลาในวันที่มีใบลาอนุมัติแล้ว
 *
 * ระบบไม่ห้ามลงเวลาในวันที่ลา เพราะผู้ที่กลับมาปฏิบัติงานกลางคันแล้วยื่นยกเลิกลาทีหลัง
 * ต้องบันทึกเวลาจริงได้ แต่ความขัดแย้งนั้นต้องไปถึงผู้ตรวจสอบเสมอ ไม่ใช่เงียบหายไป
 */
return new class extends Migration
{
    private const CODE = 'attendance.leave_conflict';

    public function up(): void
    {
        if (DB::table('notification_types')->where('code', self::CODE)->exists()) {
            return;
        }

        $now = now();
        $name = 'ลงเวลาในวันที่ลา';
        $body = 'มีการลงเวลาปฏิบัติงานในวันที่มีใบลาได้รับอนุมัติแล้ว กรุณาตรวจสอบ';

        $typeId = DB::table('notification_types')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => self::CODE,
            'module' => 'attendance',
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
