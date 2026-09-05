<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * INT-15 — กวาด outbox ของ webhook (SoT §17)
 *
 * เหตุผลเดียวกับ `notifications:dispatch-outbox` ของ INT-14: ตารางจะเป็น outbox
 * ได้ต่อเมื่อมีตัวกวาดคอยหาแถวที่ควรถูกส่งไปแล้วแต่ยังค้าง เพราะงานในคิวหายได้
 */
class DispatchWebhookOutboxCommand extends Command
{
    protected $signature = 'webhooks:dispatch-outbox
                            {--stale-minutes=5 : ข้ามแถวที่เพิ่งสร้าง เพื่อไม่ชนกับงานที่กำลังจะทำงานอยู่แล้ว}
                            {--limit=500 : จำนวนแถวสูงสุดต่อรอบ}';

    protected $description = 'ส่ง webhook ที่ค้างอยู่ใน outbox ซ้ำ เมื่องานเดิมหายไปจากคิว';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        // ประทับชีพจรก่อนเสมอ — ต้องรู้ว่างานถูกเรียก แม้รอบนี้จะไม่มีอะไรให้ทำ
        $heartbeat->record('webhooks:dispatch-outbox', 5);

        $staleBefore = now()->subMinutes((int) $this->option('stale-minutes'));

        $stale = WebhookDelivery::query()
            ->whereIn('status', ['pending', 'retrying'])
            ->where(function ($query) use ($staleBefore) {
                $query->where(fn ($pending) => $pending->where('status', 'pending')->where('created_at', '<=', $staleBefore))
                    ->orWhere(fn ($retry) => $retry->where('status', 'retrying')->where('next_attempt_at', '<=', now()));
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($stale as $deliveryId) {
            DeliverWebhook::dispatch($deliveryId);
        }

        $this->info("ส่งงานเข้าคิวใหม่ {$stale->count()} รายการ");

        return self::SUCCESS;
    }
}
