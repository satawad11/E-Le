<?php

namespace App\Console\Commands;

use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use App\Services\Operations\ExceptionQueue;
use Illuminate\Console\Command;

/**
 * INT-20 — เฝ้าระวังงานตามเวลาที่หยุดทำงาน (ปิด RISK-46 · SoT §20)
 *
 * INT-17 ทำให้ความเงียบของงานตามเวลา **มองเห็นได้** แต่ยังต้องมีคนเปิดหน้าจอดู
 * — ซึ่งไม่มีใครเปิดจนกว่าจะมีคนโทรมาถาม
 *
 * คำสั่งนี้เปลี่ยนจาก "มองเห็นได้ถ้ามีคนดู" เป็น **"ถูกยกขึ้นมาเป็นงานที่มีเจ้าของ"**
 * โดยยกเข้าคิวข้อผิดพลาดระดับ `critical` ซึ่งไหลต่อไปยังศูนย์แจ้งเตือนได้
 *
 * **ปัญหาไก่กับไข่ที่ต้องยอมรับ:** ตัวมันเองก็เป็นงานตามเวลา ถ้า `schedule:run`
 * ตายทั้งหมด คำสั่งนี้ก็ไม่ทำงานเช่นกัน · การเฝ้าระวังชั้นสุดท้ายต้องมาจาก
 * ภายนอกระบบ (uptime monitor ที่เรียก `/api/ping`) ซึ่งอยู่นอกขอบเขตของโค้ดนี้
 * — ระบุไว้ตรง ๆ ดีกว่าปล่อยให้เข้าใจว่าครอบคลุมแล้ว
 */
class CheckScheduledTaskHeartbeatsCommand extends Command
{
    protected $signature = 'operations:check-heartbeats';

    protected $description = 'ยกงานตามเวลาที่หยุดทำงานเข้าคิวข้อผิดพลาด';

    public function handle(ExceptionQueue $queue): int
    {
        $stale = ScheduledTaskHeartbeat::query()
            ->get()
            ->filter(fn (ScheduledTaskHeartbeat $beat) => $beat->isStale());

        foreach ($stale as $beat) {
            $queue->raise(
                type: 'scheduler.task_stale',
                title: "งานตามเวลา {$beat->task_key} หยุดทำงาน",
                detail: [
                    'task_key' => $beat->task_key,
                    'last_run_at' => $beat->last_run_at->toIso8601String(),
                    'expected_interval_minutes' => $beat->expected_interval_minutes,
                ],
                subject: $beat,
                severity: SystemException::SEVERITY_CRITICAL,
                dedupeKey: 'scheduler.task_stale:'.$beat->task_key,
                module: 'system',
            );
        }

        $this->info("พบงานที่หยุดทำงาน {$stale->count()} รายการ");

        return self::SUCCESS;
    }
}
