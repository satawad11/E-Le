<?php

namespace App\Services\Operations;

use App\Models\ScheduledTaskHeartbeat;
use App\Services\TrustedClock;

/**
 * ชีพจรของงานตามเวลา (ปิด RISK-40 · SoT §20)
 *
 * **ปัญหาที่ปิด:** งานที่ตั้งเวลาไว้จะ "ไม่ทำงาน" อย่างเงียบสนิทเมื่อ `schedule:run`
 * ไม่ถูกเรียก — ไม่มี error ไม่มี log ไม่มีอะไรเลย · outbox ของศูนย์แจ้งเตือน
 * และ webhook พึ่งงานตามเวลาทั้งคู่ ความเงียบจึงแปลว่าข้อความค้างโดยไม่มีใครรู้
 *
 * ทางเดียวที่จับได้คือให้แต่ละงานประทับเวลาไว้ แล้วมีคนเทียบว่ามันเงียบไปนาน
 * เกินกำหนดหรือยัง — **ตรวจจับการไม่เกิดขึ้นของเหตุการณ์** ซึ่งต่างจากการ
 * ตรวจจับข้อผิดพลาดโดยสิ้นเชิง
 */
class SchedulerHeartbeat
{
    public function __construct(private readonly TrustedClock $clock) {}

    /** @param array<string,mixed> $meta */
    public function record(string $taskKey, int $expectedIntervalMinutes, array $meta = []): void
    {
        ScheduledTaskHeartbeat::updateOrCreate(
            ['task_key' => $taskKey],
            [
                'last_run_at' => $this->clock->now(),
                'expected_interval_minutes' => $expectedIntervalMinutes,
                'meta' => $meta,
            ],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function overview(): array
    {
        return ScheduledTaskHeartbeat::query()
            ->orderBy('task_key')
            ->get()
            ->map(fn (ScheduledTaskHeartbeat $beat) => [
                'task_key' => $beat->task_key,
                'last_run_at' => $beat->last_run_at->toIso8601String(),
                'expected_interval_minutes' => $beat->expected_interval_minutes,
                'stale' => $beat->isStale(),
                'meta' => $beat->meta,
            ])
            ->all();
    }

    /** @return array<int,string> งานที่เงียบเกินกำหนด */
    public function staleTasks(): array
    {
        return ScheduledTaskHeartbeat::query()
            ->get()
            ->filter(fn (ScheduledTaskHeartbeat $beat) => $beat->isStale())
            ->pluck('task_key')
            ->values()
            ->all();
    }
}
