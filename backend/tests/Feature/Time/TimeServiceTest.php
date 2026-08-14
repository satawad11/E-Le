<?php

namespace Tests\Feature\Time;

use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Holiday;
use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleAssignment;
use App\Models\Time\WorkScheduleDay;
use App\Models\User;
use App\Models\WorkCalendarException;
use App\Services\Time\TimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEGACY-06 — บริการเวลากลาง
 *
 * ครอบตามที่เฟสกำหนด: วันทำงาน · วันหยุด · เสาร์อาทิตย์ · ข้ามวัน ·
 * ข้ามปีงบประมาณ · schedule override · timezone
 */
class TimeServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * migration `2026_08_15_110000_backfill_default_work_schedule` สร้างตารางเริ่มต้น
     * ให้ทุกการติดตั้งอยู่แล้ว ซึ่งถูกต้องสำหรับระบบจริง แต่ทำให้เทสต์ที่ต้องการ
     * ควบคุมตารางเองไม่แน่นอน จึงล้างออกก่อนแล้วให้แต่ละเทสต์ประกาศโลกของตัวเอง
     *
     * ผลของ backfill ถูกตรวจแยกไว้ที่ `WorkScheduleBackfillTest`
     */
    protected function setUp(): void
    {
        parent::setUp();

        WorkScheduleAssignment::query()->delete();
        WorkScheduleDay::query()->delete();
        WorkSchedule::query()->delete();
    }

    private function time(): TimeService
    {
        // ตารางเวลาถูก cache ต่อ request — ล้างก่อนทุกครั้งที่เพิ่งแก้ข้อมูล
        $service = app(TimeService::class);
        $service->flush();

        return $service;
    }

    /**
     * สร้างตารางเวลา — วันทำงานที่ระบุใช้เวลาเดียวกันทั้งหมด
     *
     * @param  array<int,int>  $workingDays  0 = อาทิตย์ ... 6 = เสาร์
     */
    private function makeSchedule(
        string $code,
        string $start = '08:30:00',
        string $end = '16:30:00',
        array $workingDays = [1, 2, 3, 4, 5],
        int $grace = 0,
        string $timezone = 'Asia/Bangkok',
        bool $isDefault = false,
        int $earlyGrace = 0,
    ): WorkSchedule {
        $schedule = WorkSchedule::create([
            'code' => $code,
            'name' => $code,
            'timezone' => $timezone,
            'grace_minutes' => $grace,
            'early_leave_grace_minutes' => $earlyGrace,
            'is_default' => $isDefault,
            'is_active' => true,
        ]);

        $crossesMidnight = strcmp($end, $start) <= 0;

        foreach (range(0, 6) as $dayOfWeek) {
            $working = in_array($dayOfWeek, $workingDays, true);

            WorkScheduleDay::create([
                'work_schedule_id' => $schedule->id,
                'day_of_week' => $dayOfWeek,
                'is_working_day' => $working,
                'start_time' => $working ? $start : null,
                'end_time' => $working ? $end : null,
                'crosses_midnight' => $working && $crossesMidnight,
            ]);
        }

        return $schedule->fresh('days');
    }

    private function assign(WorkSchedule $schedule, ?User $user = null, ?int $orgUnitId = null, string $from = '2000-01-01', ?string $to = null): void
    {
        WorkScheduleAssignment::create([
            'work_schedule_id' => $schedule->id,
            'user_id' => $user?->id,
            'org_unit_id' => $orgUnitId,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }

    private function orgUnit(string $code, ?int $parentId = null): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $code,
            'type' => 'department',
            'effective_from' => '2000-01-01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- วันทำงาน

    public function test_a_weekday_with_a_schedule_is_a_working_day(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        // 2026-08-12 เป็นวันพุธ
        $this->assertTrue($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $user));
    }

    // --------------------------------------------------------- เสาร์อาทิตย์

    public function test_saturday_and_sunday_are_not_working_days(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $time = $this->time();

        $this->assertFalse($time->isWorkingDay(CarbonImmutable::parse('2026-08-15'), $user), 'เสาร์');
        $this->assertFalse($time->isWorkingDay(CarbonImmutable::parse('2026-08-16'), $user), 'อาทิตย์');
    }

    public function test_a_schedule_may_declare_saturday_a_working_day(): void
    {
        // กะที่ทำงานเสาร์ด้วย — พิสูจน์ว่าเสาร์อาทิตย์ไม่ได้ถูก hard-code
        $schedule = $this->makeSchedule('six-day', workingDays: [1, 2, 3, 4, 5, 6], isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $this->assertTrue($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-15'), $user), 'เสาร์');
        $this->assertFalse($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-16'), $user), 'อาทิตย์');
    }

    // ------------------------------------------------------------- วันหยุด

    public function test_a_public_holiday_is_not_a_working_day(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        Holiday::create(['date' => '2026-08-12', 'name' => 'วันแม่แห่งชาติ', 'is_active' => true]);

        $this->assertFalse($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $user));
    }

    public function test_an_inactive_holiday_is_ignored(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        Holiday::create(['date' => '2026-08-12', 'name' => 'ยกเลิกแล้ว', 'is_active' => false]);

        $this->assertTrue($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $user));
    }

    public function test_a_department_exception_overrides_a_public_holiday(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $department = Department::create(['name' => 'ฝ่ายเวร']);
        $user = User::factory()->create(['department_id' => $department->id]);
        $this->assign($schedule);

        Holiday::create(['date' => '2026-08-12', 'name' => 'วันหยุด', 'is_active' => true]);
        WorkCalendarException::create([
            'date' => '2026-08-12',
            'department_id' => $department->id,
            'day_type' => 'working_day',
            'name' => 'อยู่เวรวันหยุด',
            'is_active' => true,
        ]);

        $this->assertTrue(
            $this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $user),
            'ฝ่ายที่ต้องอยู่เวรต้องนับเป็นวันทำงาน',
        );

        // ฝ่ายอื่นยังหยุดตามปกติ
        $other = User::factory()->create(['department_id' => Department::create(['name' => 'ฝ่ายอื่น'])->id]);
        $this->assertFalse($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $other));
    }

    public function test_an_exception_can_turn_a_normal_weekday_into_a_holiday(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        WorkCalendarException::create([
            'date' => '2026-08-12',
            'department_id' => null,
            'day_type' => 'holiday',
            'name' => 'หยุดพิเศษ',
            'is_active' => true,
        ]);

        $this->assertFalse($this->time()->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $user));
    }

    public function test_working_days_between_skips_weekends_and_holidays(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        Holiday::create(['date' => '2026-08-12', 'name' => 'วันแม่', 'is_active' => true]);

        // 10 ส.ค. (จ.) ถึง 16 ส.ค. (อา.) = 5 วันธรรมดา ลบวันหยุด 1 วัน = 4
        $days = $this->time()->workingDaysBetween(
            CarbonImmutable::parse('2026-08-10'),
            CarbonImmutable::parse('2026-08-16'),
            $user,
        );

        $this->assertSame(4.0, $days);
    }

    public function test_calendar_days_ignore_holidays_entirely(): void
    {
        $this->assertSame(
            7.0,
            $this->time()->calendarDaysBetween(
                CarbonImmutable::parse('2026-08-10'),
                CarbonImmutable::parse('2026-08-16'),
            ),
        );
    }

    // --------------------------------------------------------------- ข้ามวัน

    public function test_an_overnight_shift_ends_on_the_next_day(): void
    {
        $schedule = $this->makeSchedule('night', start: '22:00:00', end: '06:00:00', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $window = $this->time()->shiftWindow($user, CarbonImmutable::parse('2026-08-12'));

        $this->assertNotNull($window);
        $this->assertTrue($window['crosses_midnight']);
        $this->assertSame('2026-08-12 22:00:00', $window['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-13 06:00:00', $window['end']->format('Y-m-d H:i:s'), 'กะดึกต้องจบวันถัดไป');
        $this->assertSame(480, $this->time()->scheduledWorkMinutes($user, CarbonImmutable::parse('2026-08-12')));
    }

    public function test_a_day_shift_does_not_cross_midnight(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $window = $this->time()->shiftWindow($user, CarbonImmutable::parse('2026-08-12'));

        $this->assertFalse($window['crosses_midnight']);
        $this->assertSame('2026-08-12 16:30:00', $window['end']->format('Y-m-d H:i:s'));
    }

    public function test_checking_out_after_midnight_on_a_night_shift_is_not_early_leave(): void
    {
        $schedule = $this->makeSchedule('night', start: '22:00:00', end: '06:00:00', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        // ออกงาน 06:05 ของวันถัดไป — ยังถือว่าครบกะของวันที่ 12
        $early = $this->time()->earlyLeaveMinutes(
            $user,
            CarbonImmutable::parse('2026-08-13 06:05:00', 'Asia/Bangkok'),
            CarbonImmutable::parse('2026-08-12'),
        );

        $this->assertSame(0, $early);
    }

    // ------------------------------------------------------------- มาสาย

    public function test_late_minutes_are_counted_from_the_scheduled_start(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $late = $this->time()->lateMinutes(
            $user,
            CarbonImmutable::parse('2026-08-12 08:45:00', 'Asia/Bangkok'),
        );

        $this->assertSame(15, $late);
    }

    public function test_the_grace_period_is_honoured(): void
    {
        $schedule = $this->makeSchedule('office', grace: 15, isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $time = $this->time();

        $this->assertSame(0, $time->lateMinutes($user, CarbonImmutable::parse('2026-08-12 08:45:00', 'Asia/Bangkok')), 'ยังอยู่ในเวลาผ่อนผัน');
        $this->assertSame(5, $time->lateMinutes($user, CarbonImmutable::parse('2026-08-12 08:50:00', 'Asia/Bangkok')), 'เกินผ่อนผัน 5 นาที');
    }

    public function test_nobody_is_late_on_a_holiday(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        Holiday::create(['date' => '2026-08-12', 'name' => 'วันแม่', 'is_active' => true]);

        $this->assertSame(
            0,
            $this->time()->lateMinutes($user, CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Bangkok')),
            'ของเดิมนับสายแม้ในวันหยุด — ต้องไม่เกิดขึ้นอีก',
        );
    }

    public function test_nobody_is_late_on_a_weekend(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $this->assertSame(
            0,
            $this->time()->lateMinutes($user, CarbonImmutable::parse('2026-08-15 10:00:00', 'Asia/Bangkok')),
        );
    }

    public function test_early_leave_is_measured_against_the_scheduled_end(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $this->assertSame(
            30,
            $this->time()->earlyLeaveMinutes($user, CarbonImmutable::parse('2026-08-12 16:00:00', 'Asia/Bangkok')),
        );
    }

    // ------------------------------------------------------------- ขาดงาน

    public function test_absence_needs_a_working_day_and_no_attendance(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $time = $this->time();
        $workday = CarbonImmutable::parse('2026-08-12');

        $this->assertTrue($time->isAbsent($user, $workday, hasAttendance: false));
        $this->assertFalse($time->isAbsent($user, $workday, hasAttendance: true), 'ลงเวลาแล้วไม่ถือว่าขาด');
        $this->assertFalse($time->isAbsent($user, $workday, hasAttendance: false, onApprovedLeave: true), 'ลาที่อนุมัติแล้วไม่ถือว่าขาด');
        $this->assertFalse($time->isAbsent($user, CarbonImmutable::parse('2026-08-15'), hasAttendance: false), 'เสาร์ไม่ถือว่าขาด');
    }

    // ------------------------------------------------------- schedule override

    public function test_a_personal_schedule_overrides_the_organisation_default(): void
    {
        $default = $this->makeSchedule('office', isDefault: true);
        $personal = $this->makeSchedule('flex', start: '10:00:00', end: '18:00:00');

        $user = User::factory()->create();
        $this->assign($default);
        $this->assign($personal, user: $user);

        $window = $this->time()->shiftWindow($user, CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('10:00:00', $window['start']->format('H:i:s'));
        $this->assertSame(0, $this->time()->lateMinutes($user, CarbonImmutable::parse('2026-08-12 09:30:00', 'Asia/Bangkok')), 'มาก่อนเวลาของตารางตนเอง');
    }

    public function test_an_org_unit_schedule_applies_to_its_members(): void
    {
        $default = $this->makeSchedule('office', isDefault: true);
        $unitSchedule = $this->makeSchedule('unit-hours', start: '07:00:00', end: '15:00:00');

        $unitId = $this->orgUnit('UNIT-A');
        $user = User::factory()->create(['org_unit_id' => $unitId]);

        $this->assign($default);
        $this->assign($unitSchedule, orgUnitId: $unitId);

        $window = $this->time()->shiftWindow($user->fresh(), CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('07:00:00', $window['start']->format('H:i:s'));
    }

    public function test_a_child_unit_inherits_the_parent_unit_schedule(): void
    {
        $default = $this->makeSchedule('office', isDefault: true);
        $facultySchedule = $this->makeSchedule('faculty-hours', start: '09:00:00', end: '17:00:00');

        $faculty = $this->orgUnit('FACULTY');
        $child = $this->orgUnit('CHILD', $faculty);
        $user = User::factory()->create(['org_unit_id' => $child]);

        $this->assign($default);
        $this->assign($facultySchedule, orgUnitId: $faculty);

        $window = $this->time()->shiftWindow($user->fresh(), CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('09:00:00', $window['start']->format('H:i:s'), 'หน่วยงานลูกต้องรับตารางของหน่วยงานแม่');
    }

    public function test_a_personal_schedule_beats_the_org_unit_schedule(): void
    {
        $unitSchedule = $this->makeSchedule('unit-hours', start: '07:00:00', end: '15:00:00');
        $personal = $this->makeSchedule('personal', start: '11:00:00', end: '19:00:00');

        $unitId = $this->orgUnit('UNIT-A');
        $user = User::factory()->create(['org_unit_id' => $unitId]);

        $this->assign($unitSchedule, orgUnitId: $unitId);
        $this->assign($personal, user: $user);

        $window = $this->time()->shiftWindow($user->fresh(), CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('11:00:00', $window['start']->format('H:i:s'));
    }

    // -------------------------------------------------------- effective date

    public function test_an_expired_assignment_stops_applying(): void
    {
        $default = $this->makeSchedule('office', isDefault: true);
        $old = $this->makeSchedule('old-hours', start: '07:30:00', end: '15:30:00');

        $user = User::factory()->create();
        $this->assign($default);
        $this->assign($old, user: $user, from: '2020-01-01', to: '2026-08-11');

        $window = $this->time()->shiftWindow($user, CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('08:30:00', $window['start']->format('H:i:s'), 'ตารางที่หมดอายุแล้วต้องไม่ถูกใช้');
    }

    /**
     * หัวใจของ effective dating: แก้ตารางเวลาวันนี้ต้องไม่เปลี่ยนผลของเมื่อวาน
     */
    public function test_changing_the_schedule_does_not_rewrite_history(): void
    {
        $old = $this->makeSchedule('old-hours', start: '08:00:00', end: '16:00:00', isDefault: true);
        $user = User::factory()->create();
        $this->assign($old, from: '2020-01-01', to: '2026-08-11');

        $new = $this->makeSchedule('new-hours', start: '09:00:00', end: '17:00:00');
        $this->assign($new, from: '2026-08-12');

        $time = $this->time();

        // มาถึง 08:30 — สายครึ่งชั่วโมงตามตารางเก่า แต่ไม่สายตามตารางใหม่
        $this->assertSame(30, $time->lateMinutes($user, CarbonImmutable::parse('2026-08-10 08:30:00', 'Asia/Bangkok')));
        $this->assertSame(0, $time->lateMinutes($user, CarbonImmutable::parse('2026-08-12 08:30:00', 'Asia/Bangkok')));
    }

    public function test_a_future_assignment_does_not_apply_yet(): void
    {
        $default = $this->makeSchedule('office', isDefault: true);
        $future = $this->makeSchedule('future-hours', start: '06:00:00', end: '14:00:00');

        $user = User::factory()->create();
        $this->assign($default);
        $this->assign($future, user: $user, from: '2027-01-01');

        $window = $this->time()->shiftWindow($user, CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('08:30:00', $window['start']->format('H:i:s'));
    }

    // ------------------------------------------------------------- timezone

    public function test_the_default_timezone_is_bangkok(): void
    {
        $this->assertSame('Asia/Bangkok', config('app.timezone'));
        $this->assertSame('Asia/Bangkok', $this->time()->timezoneFor());
    }

    public function test_a_schedule_may_declare_its_own_timezone(): void
    {
        $schedule = $this->makeSchedule('tokyo', timezone: 'Asia/Tokyo', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $this->assertSame('Asia/Tokyo', $this->time()->timezoneFor($user));

        $window = $this->time()->shiftWindow($user, CarbonImmutable::parse('2026-08-12'));
        $this->assertSame('Asia/Tokyo', $window['start']->timezone->getName());
    }

    /**
     * เวลาที่รับมาเป็น UTC ต้องถูกแปลงเป็นเวลาท้องถิ่นก่อนตัดสินว่าสายหรือไม่
     *
     * 02:00 UTC = 09:00 เวลาไทย → สาย 30 นาทีจากเวลาเริ่ม 08:30
     */
    public function test_a_utc_timestamp_is_interpreted_in_the_local_zone(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $late = $this->time()->lateMinutes(
            $user,
            CarbonImmutable::parse('2026-08-12 02:00:00', 'UTC'),
            CarbonImmutable::parse('2026-08-12'),
        );

        $this->assertSame(30, $late);
    }

    public function test_converting_a_moment_into_the_user_zone(): void
    {
        $schedule = $this->makeSchedule('office', isDefault: true);
        $user = User::factory()->create();
        $this->assign($schedule);

        $converted = $this->time()->toUserZone(CarbonImmutable::parse('2026-08-12 02:00:00', 'UTC'), $user);

        $this->assertSame('09:00:00', $converted->format('H:i:s'));
        $this->assertSame('Asia/Bangkok', $converted->timezone->getName());
    }

    // -------------------------------------------------------- ปีงบประมาณ

    private function seedFiscalYears(): void
    {
        // ปีงบ พ.ศ.2569 = 1 ต.ค. 2025 – 30 ก.ย. 2026
        FiscalYear::create(['name' => 'ปีงบประมาณ 2569', 'year' => 2569, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_current' => true]);
        FiscalYear::create(['name' => 'ปีงบประมาณ 2570', 'year' => 2570, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_current' => false]);
    }

    public function test_the_fiscal_year_number_is_the_gregorian_year_it_ends_in(): void
    {
        $this->seedFiscalYears();
        $time = $this->time();

        $this->assertSame(2026, $time->fiscalYearNumber(CarbonImmutable::parse('2026-08-12')));
        $this->assertSame(2027, $time->fiscalYearNumber(CarbonImmutable::parse('2026-10-01')));
    }

    public function test_a_range_that_spans_the_fiscal_year_boundary_is_detected(): void
    {
        $this->seedFiscalYears();
        $time = $this->time();

        $this->assertTrue(
            $time->crossesFiscalYear(CarbonImmutable::parse('2026-09-29'), CarbonImmutable::parse('2026-10-02')),
            'ลาคร่อม 30 ก.ย. ต้องนับว่าข้ามปีงบ',
        );

        $this->assertFalse(
            $time->crossesFiscalYear(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')),
        );
    }

    public function test_the_fiscal_year_range_comes_from_the_table(): void
    {
        $this->seedFiscalYears();

        $range = $this->time()->fiscalYearRange(CarbonImmutable::parse('2026-08-12'));

        $this->assertSame('2025-10-01', $range['start']->toDateString());
        $this->assertSame('2026-09-30', $range['end']->toDateString());
    }

    /**
     * ยังไม่ได้ตั้งปีงบ ต้องถอยไปใช้กฎ "ปีงบเริ่ม 1 ตุลาคม" ไม่ใช่ล้ม
     */
    public function test_the_fiscal_year_falls_back_when_no_row_covers_the_date(): void
    {
        $time = $this->time();

        $this->assertSame(2026, $time->fiscalYearNumber(CarbonImmutable::parse('2026-08-12')));
        $this->assertSame(2027, $time->fiscalYearNumber(CarbonImmutable::parse('2026-10-01')));
        $this->assertNull($time->fiscalYearRange(CarbonImmutable::parse('2026-08-12')));
    }

    // ------------------------------------------------ ไม่มีตารางเวลาเลย

    public function test_without_any_schedule_weekdays_still_count_as_working_days(): void
    {
        $user = User::factory()->create();
        $time = $this->time();

        $this->assertTrue($time->isWorkingDay(CarbonImmutable::parse('2026-08-12'), $user), 'พฤหัสฯ');
        $this->assertFalse($time->isWorkingDay(CarbonImmutable::parse('2026-08-15'), $user), 'เสาร์');
        $this->assertNull($time->shiftWindow($user, CarbonImmutable::parse('2026-08-12')));
        $this->assertSame(0, $time->lateMinutes($user, CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Bangkok')));
    }
}
