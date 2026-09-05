<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * INT-14 — สร้างคู่กุญแจ VAPID สำหรับ Web Push (SoT §16)
 *
 * **แสดงกุญแจอย่างเดียว ไม่เขียนลง `.env` ให้เอง** — การเขียนไฟล์ตั้งค่าให้
 * อัตโนมัติทำให้กุญแจเดิมถูกทับโดยไม่ตั้งใจได้ ซึ่งจะตัดการแจ้งเตือนของทุกคน
 * ที่สมัครไว้แล้วพร้อมกันทั้งระบบ · ผู้ดูแลคัดลอกไปวางเองปลอดภัยกว่า
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'notifications:generate-vapid-keys';

    protected $description = 'สร้างคู่กุญแจ VAPID สำหรับช่องทาง Web Push';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $exception) {
            $this->error('สร้างกุญแจไม่สำเร็จ: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (filled(config('services.web_push.public_key'))) {
            $this->warn('ระบบมีกุญแจ VAPID อยู่แล้ว — การเปลี่ยนกุญแจจะทำให้ผู้ที่สมัครรับไว้แล้วทั้งหมดต้องสมัครใหม่');
        }

        $this->line('เพิ่มสองบรรทัดนี้ลงใน .env แล้วรัน php artisan config:clear');
        $this->newLine();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
