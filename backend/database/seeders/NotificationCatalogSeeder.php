<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * R02-9 — ประเภทและแม่แบบการแจ้งเตือนตั้งต้นของ Notification Center (NOTIFY-01)
 *
 * เดิม migration create_notification_center และ add_leave_cancellation_notification_types เป็นผู้ใส่ข้อมูลนี้
 * baseline ใหม่แยกข้อมูลออกจาก schema จึงย้ายมาเป็น seeder (ข้อมูลส่งออกจากฐานข้อมูลทดสอบของชุดเดิม)
 *
 * idempotent: ประเภทค้นตาม `code` · แม่แบบค้นตาม (ประเภท, ช่องทาง, รุ่น, ภาษา) ไม่ทับข้อความที่ผู้ดูแลแก้ไว้
 */
class NotificationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ($this->fixture('notification_types.json') as $type) {
            $query = DB::table('notification_types')->where('code', $type['code']);

            if ($query->exists()) {
                $query->update($this->encode($type) + ['updated_at' => $now]);
            } else {
                DB::table('notification_types')->insert($this->encode($type) + [
                    'uuid' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $types = DB::table('notification_types')->pluck('id', 'code');

        foreach ($this->fixture('notification_templates.json') as $template) {
            $key = [
                'notification_type_id' => $types[$template['notification_type']],
                'channel' => $template['channel'],
                'version' => $template['version'],
                'locale' => $template['locale'],
            ];

            if (DB::table('notification_templates')->where($key)->exists()) {
                continue;
            }

            DB::table('notification_templates')->insert($key + $this->encode(array_diff_key($template, array_flip(['notification_type']))) + [
                'uuid' => (string) Str::uuid(),
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fixture(string $file): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/data/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encode(array $row): array
    {
        return array_map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value, $row);
    }
}
