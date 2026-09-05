<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotification;
use App\Models\NotificationCenter\NotificationDelivery;
use App\Services\Modules\ModuleRegistry;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * INT-14 — กวาด outbox ของศูนย์แจ้งเตือน (SoT §16)
 *
 * **ปิดช่องที่งานหายแล้วไม่มีใครรู้**
 *
 * ศูนย์แจ้งเตือนสร้างแถวใน `notification_deliveries` แล้ว dispatch job ทันที
 * ซึ่งใช้ได้ตราบใดที่ job ไม่หาย · แต่ job หายได้จริงหลายทาง: worker ถูกฆ่า
 * ระหว่างทำงาน, Redis ถูกล้าง, การ deploy ที่ตัดคิวทิ้ง, หรือ retry ที่ตั้งเวลาไว้
 * ข้ามช่วงที่ระบบดับพอดี · แถวที่ค้างสถานะ `pending`/`retrying` จะไม่มีวันถูกส่ง
 * และไม่มีใครรู้ว่ามันมีอยู่
 *
 * ตารางเดลิเวอรีจึงเป็น outbox ที่แท้จริงได้ต่อเมื่อมีตัวกวาดคอยเทียบว่า
 * "แถวที่ควรถูกส่งไปแล้ว" กับ "แถวที่ถูกส่งจริง" ตรงกันหรือไม่
 *
 * ปลอดภัยเมื่อรันซ้ำ — `NotificationDeliveryService::deliver()` ตรวจสถานะก่อนส่ง
 * และข้ามแถวที่ถูกส่งไปแล้วหรืออยู่ระหว่างส่ง
 */
class DispatchNotificationOutboxCommand extends Command
{
    protected $signature = 'notifications:dispatch-outbox
                            {--stale-minutes=5 : ข้ามแถวที่เพิ่งสร้าง เพื่อไม่ชนกับ job ที่กำลังจะทำงานอยู่แล้ว}
                            {--limit=500 : จำนวนแถวสูงสุดต่อรอบ}';

    protected $description = 'ส่งการแจ้งเตือนที่ค้างอยู่ใน outbox ซ้ำ เมื่องานเดิมหายไปจากคิว';

    public function handle(ModuleRegistry $modules, SchedulerHeartbeat $heartbeat): int
    {
        // ประทับชีพจรก่อนเสมอ — ต้องรู้ว่างานถูกเรียก แม้รอบนี้จะไม่มีอะไรให้ทำ
        $heartbeat->record('notifications:dispatch-outbox', 5);

        if (! $modules->isEnabled('notification')) {
            $this->info('โมดูลการแจ้งเตือนปิดอยู่ — ไม่ทำอะไร');

            return self::SUCCESS;
        }

        $staleBefore = now()->subMinutes((int) $this->option('stale-minutes'));

        $stale = NotificationDelivery::query()
            ->whereIn('status', ['pending', 'retrying'])
            ->where(function ($query) use ($staleBefore) {
                /*
                 * `pending` ที่เก่ากว่าเกณฑ์ = job แรกน่าจะหายไปแล้ว
                 * `retrying` ที่ถึงเวลานัดแล้ว = job ที่ตั้งเวลาไว้ไม่ได้ทำงาน
                 */
                $query->where(fn ($pending) => $pending->where('status', 'pending')->where('created_at', '<=', $staleBefore))
                    ->orWhere(fn ($retry) => $retry->where('status', 'retrying')->where('next_attempt_at', '<=', now()));
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($stale as $deliveryId) {
            DeliverNotification::dispatch($deliveryId);
        }

        $this->info("ส่งงานเข้าคิวใหม่ {$stale->count()} รายการ");

        return self::SUCCESS;
    }
}
