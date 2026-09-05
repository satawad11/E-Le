<?php

namespace App\Console\Commands;

use App\Services\Operations\ExceptionQueue;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * INT-17 — ยกข้อผิดพลาดที่ระบบรู้อยู่แล้วเข้าคิว (SoT §19)
 *
 * dead-letter ของศูนย์แจ้งเตือน (INT-14) และของ webhook (INT-15) ถูกบันทึกไว้
 * ตั้งแต่ตอนนั้นแล้ว แต่ **ยังไม่มีใครถูกมอบหมายให้ตามแก้** · คำสั่งนี้ทำให้ของที่
 * มีอยู่แล้วกลายเป็นงานที่มีเจ้าของ โดยไม่ต้องแก้โค้ดที่บันทึกมันไว้
 *
 * ปลอดภัยเมื่อรันซ้ำ — `raise()` รวมรายการเดิมด้วย `dedupe_key` ไม่สร้างแถวใหม่
 */
class SweepOperationalExceptionsCommand extends Command
{
    protected $signature = 'operations:sweep-exceptions';

    protected $description = 'ยก dead-letter ที่ค้างอยู่เข้าคิวข้อผิดพลาดเพื่อให้มีคนตามแก้';

    public function handle(ExceptionQueue $queue, SchedulerHeartbeat $heartbeat): int
    {
        $heartbeat->record('operations:sweep-exceptions', 15);

        $raised = $queue->sweepDeadLetters();

        $this->info("ตรวจสอบรายการที่ล้มเหลว {$raised} รายการ");

        return self::SUCCESS;
    }
}
