<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ส่ง webhook หนึ่งครั้งพร้อมลายเซ็นและกติกาการลองใหม่ (SoT §17)
 *
 * **ลายเซ็น HMAC-SHA256 พร้อมเวลา** — ปลายทางตรวจได้ว่าข้อความมาจากระบบนี้จริง
 * และเป็นข้อความใหม่ ไม่ใช่ข้อความเก่าที่ถูกดักไว้แล้วส่งซ้ำ (replay)
 * เวลาอยู่ในข้อความที่ถูกเซ็นด้วย ไม่ใช่แค่ส่งมาเฉย ๆ ไม่งั้นผู้โจมตีแก้เวลาได้
 *
 * **หน่วงเพิ่มเป็นทวีคูณ** เหมือนศูนย์แจ้งเตือน แล้วเข้า dead-letter เมื่อครบครั้ง
 */
class WebhookSender
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly TrustedClock $clock) {}

    public function send(int $deliveryId, bool $scheduleRetry = true): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($deliveryId);

        if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying'], true)) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if (! $endpoint || ! $endpoint->is_active) {
            $delivery->forceFill([
                'status' => 'failed',
                'last_error' => 'ปลายทางถูกปิดหรือถูกลบไปแล้ว',
                'dead_lettered_at' => $this->clock->now(),
            ])->save();

            return;
        }

        $body = json_encode([
            'event' => $delivery->event,
            'occurred_at' => $delivery->created_at?->toIso8601String(),
            'correlation_id' => $delivery->correlation_id,
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = (string) $this->clock->now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

        $delivery->forceFill([
            'status' => 'sending',
            'attempt_count' => $delivery->attempt_count + 1,
            'last_attempt_at' => $this->clock->now(),
        ])->save();

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Event' => $delivery->event,
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature' => 'sha256='.$signature,
                'X-Request-ID' => (string) $delivery->correlation_id,
            ])->timeout(self::TIMEOUT_SECONDS)->withBody($body, 'application/json')->post($endpoint->url);

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => 'sent',
                    'sent_at' => $this->clock->now(),
                    'response_status' => $response->status(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                ])->save();

                return;
            }

            $this->recordFailure($delivery, "ปลายทางตอบกลับ {$response->status()}", $scheduleRetry, $response->status());
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, $exception->getMessage(), $scheduleRetry);
        }
    }

    private function recordFailure(WebhookDelivery $delivery, string $error, bool $scheduleRetry, ?int $status = null): void
    {
        $terminal = $delivery->attempt_count >= $delivery->max_attempts;
        $nextAttempt = $terminal ? null : $this->clock->now()->copy()->addMinutes(2 ** max(0, $delivery->attempt_count - 1));

        $delivery->forceFill([
            'status' => $terminal ? 'failed' : 'retrying',
            'next_attempt_at' => $nextAttempt,
            'response_status' => $status,
            'last_error' => mb_substr(strip_tags($error), 0, 1000),
            'dead_lettered_at' => $terminal ? $this->clock->now() : null,
        ])->save();

        if (! $terminal && $scheduleRetry) {
            DeliverWebhook::dispatch($delivery->id)->delay($nextAttempt);
        }
    }
}
