<?php

namespace App\Services\NotificationCenter\Providers;

use App\Models\NotificationCenter\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * ตัวส่งข้อความ Web Push (SoT §16)
 *
 * **ใช้ไลบรารีมาตรฐานแทนการเขียนการเข้ารหัสเอง** — Web Push บังคับให้เข้ารหัส
 * payload ด้วย ECDH P-256 + HKDF + AES-128-GCM และเซ็น JWT ด้วย ES256
 * การเขียนเองผิดเพียงจุดเดียวจะทำให้ข้อความส่งไม่ถึงแบบเงียบ ๆ หรือแย่กว่านั้น
 * คือเข้ารหัสไม่จริง · หลักการ REUSE > CREATE ใช้กับไลบรารีภายนอกด้วยเช่นกัน
 *
 * **ปลายทางที่หมดอายุจะถูกลบทิ้ง** — เบราว์เซอร์ที่ถอนสิทธิ์หรือถูกล้างข้อมูล
 * จะคืน 404/410 ถ้าเก็บไว้ต่อ ทุกการแจ้งเตือนจะพยายามส่งไปยังปลายทางที่ตายแล้ว
 * ครบจำนวนครั้งเสมอ แล้วไปกอง dead-letter โดยไม่มีประโยชน์
 */
class WebPushGateway
{
    /** สร้าง WebPush ใหม่ทุกครั้งที่ส่ง เพื่อไม่ให้ค่าตั้งค่าที่เปลี่ยนกลางคันถูกแช่ไว้ */
    public function configured(): bool
    {
        return filled(config('services.web_push.public_key'))
            && filled(config('services.web_push.private_key'));
    }

    public function send(WebPushSubscription $subscription, string $title, string $body, ?string $url = null): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $client = new WebPush(['VAPID' => [
                'subject' => (string) config('services.web_push.subject'),
                'publicKey' => (string) config('services.web_push.public_key'),
                'privateKey' => (string) config('services.web_push.private_key'),
            ]]);

            $report = $client->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->p256dh_key,
                        'auth' => $subscription->auth_token,
                    ],
                ]),
                json_encode([
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                ], JSON_UNESCAPED_UNICODE),
            );

            if ($report->isSuccess()) {
                $subscription->forceFill(['last_used_at' => now()])->save();

                return true;
            }

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
            }

            return false;
        } catch (Throwable $exception) {
            Log::warning('ส่ง Web Push ไม่สำเร็จ', [
                'subscription_id' => $subscription->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
