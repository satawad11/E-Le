<?php

namespace App\Listeners;

use App\Models\SystemException;
use App\Services\Operations\ExceptionQueue;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Events\Dispatcher;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * INT-21 — ทำให้ผลของการสำรองข้อมูลมองเห็นได้ในระบบ (SoT §27)
 *
 * **ปัญหาจริงที่ปิด — พบระหว่างทำเช็คพอยต์นี้**
 *
 * `config/backup.php` ส่งการแจ้งเตือนความล้มเหลวไปที่ `your@example.com`
 * ซึ่งเป็นค่าตัวอย่างของแพ็กเกจ · ผลคือ **การสำรองข้อมูลที่ล้มเหลวทุกคืน
 * ไม่มีใครรู้เลย** จนถึงวันที่ต้องใช้ไฟล์สำรองจริง
 *
 * ตัวรับฟังนี้พาผลลัพธ์เข้าสู่ช่องทางที่ระบบนี้ใช้อยู่แล้ว:
 *
 *   ล้มเหลว → คิวข้อผิดพลาดระดับ `critical` (INT-17) ซึ่งมีเจ้าของและติดตามได้
 *   สำเร็จ  → ชีพจร (INT-17) ซึ่งทำให้ "การสำรองที่เงียบหายไป" ถูกจับได้ด้วย
 *
 * **REUSE ทั้งสองกลไก ไม่สร้างช่องทางแจ้งเตือนใหม่**
 */
class RecordBackupOutcome
{
    public function __construct(
        private readonly ExceptionQueue $queue,
        private readonly SchedulerHeartbeat $heartbeat,
    ) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            BackupHasFailed::class => 'onFailure',
            CleanupHasFailed::class => 'onCleanupFailure',
            UnhealthyBackupWasFound::class => 'onUnhealthy',
            BackupWasSuccessful::class => 'onSuccess',
        ];
    }

    public function onFailure(BackupHasFailed $event): void
    {
        $this->queue->raise(
            type: 'backup.failed',
            title: 'สำรองข้อมูลไม่สำเร็จ',
            detail: ['error' => $event->exception->getMessage()],
            severity: SystemException::SEVERITY_CRITICAL,
            dedupeKey: 'backup.failed',
            module: 'system',
        );
    }

    public function onCleanupFailure(CleanupHasFailed $event): void
    {
        /*
         * การล้างไฟล์เก่าล้มเหลวไม่ได้ทำให้ข้อมูลหาย แต่ทำให้พื้นที่เต็มในที่สุด
         * ซึ่งจะทำให้การสำรองครั้งต่อไปล้มเหลว — เป็นคำเตือน ไม่ใช่วิกฤต
         */
        $this->queue->raise(
            type: 'backup.cleanup_failed',
            title: 'ล้างไฟล์สำรองเก่าไม่สำเร็จ',
            detail: ['error' => $event->exception->getMessage()],
            severity: SystemException::SEVERITY_WARNING,
            dedupeKey: 'backup.cleanup_failed',
            module: 'system',
        );
    }

    public function onUnhealthy(UnhealthyBackupWasFound $event): void
    {
        $this->queue->raise(
            type: 'backup.unhealthy',
            title: 'ไฟล์สำรองอยู่ในสภาพที่ใช้ไม่ได้',
            detail: [
                'destination' => $event->diskName,
                'backup_name' => $event->backupName,
                // ข้อความจากตัวตรวจสุขภาพของแพ็กเกจ — บอกว่าไฟล์สำรองผิดตรงไหน
                'failures' => $event->failureMessages->all(),
            ],
            severity: SystemException::SEVERITY_CRITICAL,
            dedupeKey: 'backup.unhealthy',
            module: 'system',
        );
    }

    /**
     * สำเร็จ → ประทับชีพจร
     *
     * ทำให้ **การสำรองที่เงียบหายไป** ถูกจับได้ด้วยกลไกเดียวกับงานตามเวลาอื่น
     * (`operations:check-heartbeats` ของ INT-20) · ตั้งช่วงที่คาดไว้ 24 ชั่วโมง
     * ตามตารางที่ `routes/console.php` ตั้งไว้ (`backup:run` รายวัน 02:00)
     */
    public function onSuccess(BackupWasSuccessful $event): void
    {
        $this->heartbeat->record('backup:run', 1440, [
            'destination' => $event->diskName,
            'backup_name' => $event->backupName,
        ]);
    }
}
