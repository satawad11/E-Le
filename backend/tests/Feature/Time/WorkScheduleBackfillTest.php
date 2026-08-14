<?php

namespace Tests\Feature\Time;

use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleAssignment;
use App\Models\User;
use App\Services\Time\TimeService;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LEGACY-06 · 06-2 — การสร้างตารางเวลาเริ่มต้นจากข้อมูลเดิม
 *
 * ตรวจผลของ migration `2026_08_15_110000_backfill_default_work_schedule`
 * ซึ่งรันอัตโนมัติในทุกการติดตั้ง
 */
class WorkScheduleBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_creates_a_default_schedule(): void
    {
        $schedule = WorkSchedule::where('code', WorkScheduleSeeder::DEFAULT_CODE)->first();

        $this->assertNotNull($schedule, 'ทุกการติดตั้งต้องมีตารางเริ่มต้น');
        $this->assertTrue($schedule->is_default);
        $this->assertTrue($schedule->is_active);
        $this->assertSame('Asia/Bangkok', $schedule->timezone);
        $this->assertSame(7, $schedule->days()->count(), 'ต้องมีครบทั้งเจ็ดวันในสัปดาห์');
    }

    public function test_the_default_schedule_works_monday_to_friday(): void
    {
        $schedule = WorkSchedule::with('days')->where('code', WorkScheduleSeeder::DEFAULT_CODE)->firstOrFail();

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $this->assertTrue($schedule->dayOfWeek($weekday)->is_working_day, "วันที่ {$weekday} ต้องเป็นวันทำงาน");
        }

        foreach ([0, 6] as $weekend) {
            $this->assertFalse($schedule->dayOfWeek($weekend)->is_working_day, "วันที่ {$weekend} ต้องไม่ใช่วันทำงาน");
        }
    }

    public function test_the_organisation_wide_assignment_exists_and_covers_everyone(): void
    {
        $assignment = WorkScheduleAssignment::whereNull('user_id')->whereNull('org_unit_id')->first();

        $this->assertNotNull($assignment, 'ต้องมีการมอบหมายระดับองค์กร');
        $this->assertNull($assignment->effective_to, 'การมอบหมายเริ่มต้นต้องไม่มีวันสิ้นสุด');

        // ผู้ใช้ที่ไม่มีตารางเฉพาะของตน ต้องได้ตารางเริ่มต้นนี้
        $user = User::factory()->create();
        $schedule = app(TimeService::class)->scheduleFor($user, CarbonImmutable::parse('2026-08-12'));

        $this->assertNotNull($schedule);
        $this->assertSame(WorkScheduleSeeder::DEFAULT_CODE, $schedule->code);
    }

    public function test_the_backfill_never_touches_the_legacy_user_columns(): void
    {
        $user = User::factory()->create(['work_start_time' => '07:00:00', 'work_end_time' => '15:00:00']);

        (new WorkScheduleSeeder)->run();

        $user->refresh();

        $this->assertSame('07:00:00', (string) $user->work_start_time, 'คอลัมน์เดิมต้องคงไว้เป็นข้อมูลอ่านอย่างเดียว');
        $this->assertSame('15:00:00', (string) $user->work_end_time);
    }

    public function test_a_user_with_custom_hours_gets_a_personal_schedule(): void
    {
        $user = User::factory()->create(['work_start_time' => '07:00:00', 'work_end_time' => '15:00:00']);

        (new WorkScheduleSeeder)->run();

        $service = app(TimeService::class);
        $service->flush();

        $window = $service->shiftWindow($user->fresh(), CarbonImmutable::parse('2026-08-12'));

        $this->assertNotNull($window);
        $this->assertSame('07:00:00', $window['start']->format('H:i:s'), 'ต้องไม่เสียเวลาทำงานที่เคยตั้งไว้');
        $this->assertSame('15:00:00', $window['end']->format('H:i:s'));
    }

    public function test_running_the_seeder_twice_changes_nothing(): void
    {
        $before = [WorkSchedule::count(), WorkScheduleAssignment::count()];

        (new WorkScheduleSeeder)->run();
        (new WorkScheduleSeeder)->run();

        $this->assertSame($before, [WorkSchedule::count(), WorkScheduleAssignment::count()]);
    }
}
