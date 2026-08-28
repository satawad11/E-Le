<?php

namespace App\Services\NotificationCenter;

use App\Models\User;
use Throwable;

/**
 * ส่งข้อความทดสอบผ่านช่องทางจริงของ Notification Center
 *
 * CLEANUP-01 (D8) — ปุ่ม "ทดสอบการแจ้งเตือน" ในหน้าตั้งค่าเดิมเรียก
 * `LineNotificationService` / `TelegramService` ตรง ๆ การทดสอบจึงผ่านแม้ช่องทางนั้น
 * จะยังส่งจริงไม่ได้ (เช่น ผู้รับยังไม่ผูกบัญชี) เพราะข้าม `isConfiguredFor` ไป
 * ที่นี่ใช้ adapter ตัวเดียวกับที่ส่งของจริง ผลทดสอบจึงเชื่อได้
 */
class NotificationChannelTester
{
    public function __construct(private readonly ChannelAdapterRegistry $adapters) {}

    public function supports(string $channel): bool
    {
        return $this->adapters->has($channel);
    }

    public function send(string $channel, User $recipient, string $subject, string $body): TestDeliveryOutcome
    {
        $adapter = $this->adapters->for($channel);

        if (! $adapter) {
            return TestDeliveryOutcome::failed('ไม่รองรับช่องทางนี้');
        }

        if (! $adapter->isConfiguredFor($recipient)) {
            return TestDeliveryOutcome::failed('ช่องทางนี้ยังตั้งค่าไม่ครบ หรือบัญชีผู้ดูแลระบบยังไม่ได้ผูกผู้รับไว้');
        }

        try {
            $result = $adapter->deliver(new NotificationMessage($subject, $body), $recipient);
        } catch (Throwable $exception) {
            report($exception);

            return TestDeliveryOutcome::failed('ส่งไม่สำเร็จ: '.$exception->getMessage());
        }

        return $result->successful
            ? TestDeliveryOutcome::sent()
            : TestDeliveryOutcome::failed($result->error ?? 'ผู้ให้บริการปฏิเสธข้อความ');
    }
}
