<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskHeartbeat;
use App\Models\SystemException;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Tests\TestCase;

/**
 * INT-21 — ผลของการสำรองข้อมูลต้องมองเห็นได้ในระบบ (SoT §27)
 *
 * **ปัญหาจริงที่พบระหว่างทำเช็คพอยต์นี้**
 *
 * `config/backup.php` ส่งการแจ้งเตือนความล้มเหลวไปที่ `your@example.com`
 * ซึ่งเป็นค่าตัวอย่างของแพ็กเกจ — **การสำรองที่ล้มเหลวทุกคืนจึงไม่มีใครรู้เลย**
 * จนถึงวันที่ต้องใช้ไฟล์สำรองจริง
 *
 * เทสต์ชุดนี้ตรึงว่าผลลัพธ์ไหลเข้าช่องทางที่ระบบนี้ใช้อยู่แล้ว ไม่ใช่อีเมล
 * ที่ไม่มีใครอ่าน
 */
class BackupOutcomeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_backup_becomes_a_critical_item_someone_owns(): void
    {
        event(new BackupHasFailed(new Exception('mkdir(): File exists')));

        $this->assertDatabaseHas('system_exceptions', [
            'type' => 'backup.failed',
            'severity' => SystemException::SEVERITY_CRITICAL,
            'status' => SystemException::STATUS_OPEN,
        ]);
    }

    /** ล้างไฟล์เก่าไม่สำเร็จ = พื้นที่จะเต็มในที่สุด — เป็นคำเตือน ไม่ใช่วิกฤต */
    public function test_a_failed_cleanup_is_a_warning_not_a_crisis(): void
    {
        event(new CleanupHasFailed(new Exception('disk full')));

        $this->assertDatabaseHas('system_exceptions', [
            'type' => 'backup.cleanup_failed',
            'severity' => SystemException::SEVERITY_WARNING,
        ]);
    }

    /** ล้มเหลวซ้ำทุกคืนต้องเป็นแถวเดียวที่นับขึ้น ไม่ใช่คิวที่ท่วมจนไม่มีใครอ่าน */
    public function test_a_backup_that_fails_every_night_stays_one_item(): void
    {
        foreach (range(1, 5) as $ignored) {
            event(new BackupHasFailed(new Exception('mkdir(): File exists')));
        }

        $this->assertSame(1, SystemException::query()->where('type', 'backup.failed')->count());
        $this->assertSame(
            5,
            SystemException::query()->where('type', 'backup.failed')->firstOrFail()->occurrence_count,
        );
    }

    /**
     * สำเร็จ → ประทับชีพจร เพื่อให้ **การสำรองที่เงียบหายไป** ถูกจับได้
     * ด้วยกลไกเดียวกับงานตามเวลาอื่น (`operations:check-heartbeats`)
     */
    public function test_a_successful_backup_records_a_heartbeat(): void
    {
        event(new BackupWasSuccessful('backup', 'E-Leave'));

        $beat = ScheduledTaskHeartbeat::query()->where('task_key', 'backup:run')->first();

        $this->assertNotNull($beat);
        $this->assertSame(1440, $beat->expected_interval_minutes);
    }

    /** การสำรองที่หยุดไปเงียบ ๆ ต้องถูกจับได้เหมือนงานตามเวลาอื่น */
    public function test_a_backup_that_stops_running_is_detected_as_stale(): void
    {
        ScheduledTaskHeartbeat::create([
            'task_key' => 'backup:run',
            'last_run_at' => now()->subDays(5),
            'expected_interval_minutes' => 1440,
        ]);

        $this->artisan('operations:check-heartbeats')->assertSuccessful();

        $this->assertDatabaseHas('system_exceptions', [
            'type' => 'scheduler.task_stale',
            'severity' => SystemException::SEVERITY_CRITICAL,
        ]);
    }
}
