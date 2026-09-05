<?php

namespace App\Services\NotificationCenter\Adapters;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Models\NotificationCenter\WebPushSubscription;
use App\Models\User;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationMessage;
use App\Services\NotificationCenter\Providers\WebPushGateway;
use App\Services\Operations\FeatureFlags;

/**
 * ช่องทาง Web Push (SoT §16)
 *
 * **หนึ่งคนมีได้หลายเบราว์เซอร์** ต่างจากช่องทางอื่นที่ปลายทางเป็นค่าเดียว
 * (อีเมลหนึ่งอัน chat id หนึ่งอัน) การส่งจึงกระจายไปทุกปลายทางที่ยังใช้ได้
 *
 * **นับว่าสำเร็จถ้าถึงอย่างน้อยหนึ่งเครื่อง** — ผู้ใช้ที่เปิดคอมพิวเตอร์ทิ้งไว้
 * ที่บ้านแล้วเครื่องนั้นส่งไม่ถึง ไม่ควรทำให้การแจ้งเตือนที่ถึงมือถือในกระเป๋า
 * ถูกนับเป็นล้มเหลวและถูกส่งซ้ำจนไปกอง dead-letter
 */
class WebPushChannelAdapter implements NotificationChannelAdapter
{
    public function __construct(
        private readonly WebPushGateway $push,
        private readonly FeatureFlags $flags,
    ) {}

    public function channel(): string
    {
        return 'web_push';
    }

    public function isConfiguredFor(User $recipient): bool
    {
        // ธง `notification.web_push` (SoT §21) — ปิดทั้งช่องทางโดยไม่ต้องลบการสมัครของผู้ใช้
        return $this->flags->enabled('notification.web_push')
            && $this->push->configured()
            && WebPushSubscription::query()->where('user_id', $recipient->id)->exists();
    }

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
    {
        $subscriptions = WebPushSubscription::query()->where('user_id', $recipient->id)->get();

        $delivered = 0;

        foreach ($subscriptions as $subscription) {
            if ($this->push->send($subscription, $message->subject, $message->body)) {
                $delivered++;
            }
        }

        return $delivered > 0
            ? DeliveryResult::sent()
            : DeliveryResult::failed('ไม่มีเบราว์เซอร์ปลายทางใดรับข้อความได้');
    }
}
