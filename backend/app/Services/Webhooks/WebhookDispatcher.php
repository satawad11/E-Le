<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Operations\FeatureFlags;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\DB;

/**
 * ส่งเหตุการณ์ของระบบออกไปยังปลายทางภายนอก (SoT §17)
 *
 * **บันทึกลง outbox ก่อนเสมอ แล้วค่อยส่ง** — เหมือนศูนย์แจ้งเตือนที่ INT-14
 * ทำไว้ · ถ้าส่งตรงโดยไม่บันทึก เหตุการณ์ที่ส่งไม่สำเร็จจะหายไปโดยไม่มีร่องรอย
 * และไม่มีทางรู้ว่าปลายทางพลาดอะไรไปบ้าง
 *
 * **ห้ามล้มธุรกรรมทางธุรกิจเพราะปลายทางล่ม** (หลักเดียวกับ SoT §16) — เมธอดนี้
 * เขียนแถวลงตารางเท่านั้น การส่งจริงเกิดใน job แยก
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly TrustedClock $clock,
        private readonly FeatureFlags $flags,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return int จำนวนปลายทางที่รับเหตุการณ์นี้
     */
    public function dispatch(string $event, array $payload): int
    {
        /*
         * ธง `system.webhooks` (SoT §21) — ปิดเพื่อหยุดส่งข้อมูลออกนอกระบบ
         * ชั่วคราวโดยไม่ต้องลบปลายทางที่ตั้งไว้ · **ธงไม่ใช่สิทธิ์** ปิดแล้วปิดกับ
         * ทุกคนรวมถึงผู้ดูแลสูงสุด
         */
        if (! $this->flags->enabled('system.webhooks')) {
            return 0;
        }

        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => in_array($event, $endpoint->events ?? [], true));

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $correlationId = request()?->attributes?->get('request_id');
        $deliveries = [];

        DB::transaction(function () use ($endpoints, $event, $payload, $correlationId, &$deliveries) {
            foreach ($endpoints as $endpoint) {
                $deliveries[] = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event,
                    'payload' => $payload,
                    'status' => 'pending',
                    'correlation_id' => $correlationId,
                ]);
            }
        });

        foreach ($deliveries as $delivery) {
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }

        return count($deliveries);
    }
}
