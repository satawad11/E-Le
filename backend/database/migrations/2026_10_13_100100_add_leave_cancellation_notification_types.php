<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ชนิดการแจ้งเตือนของขั้นตอนขอยกเลิกวันลาและผู้ปฏิบัติงานแทน — เดิมไม่มีการแจ้งเตือนเลย
 * ผู้อนุมัติจึงไม่รู้ว่ามีคำขอยกเลิกรอ และผู้ยื่นไม่รู้ผล
 */
return new class extends Migration
{
    private array $types = [
        'leave.cancellation_requested' => ['leave', 'มีคำขอยกเลิกวันลารอพิจารณา', 'มีคำขอยกเลิกวันลารอการพิจารณาในระบบ'],
        'leave.cancellation_approved' => ['leave', 'อนุมัติยกเลิกวันลาแล้ว', 'คำขอยกเลิกวันลาของคุณได้รับอนุมัติ และคืนวันลาแล้ว'],
        'leave.cancellation_rejected' => ['leave', 'ไม่อนุมัติการยกเลิกวันลา', 'คำขอยกเลิกวันลาของคุณไม่ได้รับอนุมัติ กรุณาตรวจสอบในระบบ'],
        'leave.substitute_requested' => ['leave', 'ได้รับมอบหมายให้ปฏิบัติงานแทน', 'มีผู้ขอให้คุณปฏิบัติงานแทนระหว่างลา กรุณาตอบรับในระบบ'],
        'leave.substitute_responded' => ['leave', 'ผู้ปฏิบัติงานแทนตอบกลับแล้ว', 'ผู้ปฏิบัติงานแทนตอบกลับการมอบหมายงานของใบลาแล้ว กรุณาตรวจสอบในระบบ'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->types as $code => [$module, $name, $body]) {
            if (DB::table('notification_types')->where('code', $code)->exists()) {
                continue;
            }
            $typeId = DB::table('notification_types')->insertGetId([
                'uuid' => (string) Str::uuid(), 'code' => $code, 'module' => $module,
                'name' => $name, 'default_channels' => json_encode(['in_app', 'email']),
                'is_mandatory' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (['in_app', 'email', 'line_oa', 'telegram'] as $channel) {
                DB::table('notification_templates')->insert([
                    'uuid' => (string) Str::uuid(), 'notification_type_id' => $typeId,
                    'channel' => $channel, 'version' => 1, 'locale' => 'th',
                    'subject' => $name, 'body_template' => $body,
                    'effective_from' => now()->toDateString(), 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('notification_types')->whereIn('code', array_keys($this->types))->pluck('id');
        DB::table('notification_templates')->whereIn('notification_type_id', $ids)->delete();
        DB::table('notification_types')->whereIn('id', $ids)->delete();
    }
};
