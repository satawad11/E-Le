<?php

namespace App\Services\Time;

use App\Models\FiscalYear;
use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleDay;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * LEGACY-06 — บริการเวลากลางของระบบ
 *
 * **โมดูลอื่นต้องเรียกที่นี่เท่านั้น ห้ามคำนวณวันและเวลาเองซ้ำกัน**
 *
 * ก่อนหน้านี้ตรรกะเรื่องเวลากระจายอยู่อย่างน้อยสองที่ที่ไม่รู้จักกัน:
 *   - `LeaveService::calculateTotalDays()` รู้เรื่องวันหยุดและ exception รายหน่วยงาน
 *     แต่ไม่รู้เรื่องตารางเวลา
 *   - `AttendanceController::checkIn()` รู้เรื่องเวลาเริ่มงานของรายบุคคล
 *     แต่ **ไม่รู้จักวันหยุดเลย** และไม่มีการผ่อนผัน
 *
 * ผลคือระบบตอบคำถามว่า "วันนี้เป็นวันทำงานไหม" ได้ไม่เหมือนกันแล้วแต่ว่าใครถาม
 *
 * ทุก method รับ/คืนเวลาในเขตเวลาของตารางเวลานั้น ๆ (ค่าเริ่มต้น Asia/Bangkok)
 */
class TimeService
{
    public const DEFAULT_TIMEZONE = 'Asia/Bangkok';

    public function __construct(
        private readonly WorkScheduleResolver $schedules,
        private readonly WorkingCalendar $calendar,
    ) {}

    // ------------------------------------------------------------------ เขตเวลา

    /**
     * เขตเวลาที่ใช้กับผู้ใช้คนนี้ ณ วันนี้
     *
     * มาจากตารางเวลาที่ผูกอยู่ ไม่ใช่ค่าคงที่ในโค้ด เพื่อให้หน่วยงานที่อยู่คนละ
     * เขตเวลาใช้ของตัวเองได้โดยไม่ต้องแก้โปรแกรม
     */
    public function timezoneFor(?User $user = null, ?CarbonInterface $date = null): string
    {
        $date ??= $this->today();

        return $this->schedules->resolve($user, $date)?->timezone
            ?? config('app.timezone', self::DEFAULT_TIMEZONE);
    }

    public function now(?User $user = null): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezoneFor($user));
    }

    public function today(?User $user = null): CarbonImmutable
    {
        return CarbonImmutable::now($user === null
            ? config('app.timezone', self::DEFAULT_TIMEZONE)
            : $this->timezoneFor($user))->startOfDay();
    }

    /**
     * แปลงเวลาที่รับมาให้อยู่ในเขตเวลาของผู้ใช้
     *
     * ใช้ตอนรับเวลาจาก client หรือจากระบบภายนอกที่อาจส่ง UTC มา
     */
    public function toUserZone(CarbonInterface $moment, ?User $user = null): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone($this->timezoneFor($user, $moment));
    }

    // -------------------------------------------------------------- ตารางเวลา

    public function scheduleFor(?User $user, ?CarbonInterface $date = null): ?WorkSchedule
    {
        return $this->schedules->resolve($user, $date ?? $this->today());
    }

    public function scheduleDayFor(?User $user, CarbonInterface $date): ?WorkScheduleDay
    {
        return $this->scheduleFor($user, $date)?->dayOfWeek($date->dayOfWeek);
    }

    // ----------------------------------------------------------- วัน/วันทำงาน

    /**
     * วันนี้เป็นวันทำงานของผู้ใช้คนนี้หรือไม่
     *
     * ลำดับ: exception รายหน่วยงาน > exception ระดับองค์กร > วันหยุดราชการ > ตารางเวลา
     */
    public function isWorkingDay(CarbonInterface $date, ?User $user = null, ?int $orgUnitId = null): bool
    {
        // R02: exception รายหน่วยอ้าง org_units (ตาราง departments ถูกตัด) — หน่วยของผู้ใช้คือหน่วยต้นสังกัด (assignment primary)
        if ($orgUnitId === null && $user !== null) {
            $orgUnitId = app(OrgScopeResolver::class)->homeOrgUnitId($user);
        }

        $exception = $this->calendar->calendarException($date, $orgUnitId);

        if ($exception !== null) {
            // วันหยุดชดเชย/วันทำงานพิเศษที่ผู้ดูแลกำหนดเอง ชนะทุกอย่าง
            return $exception;
        }

        if ($this->calendar->isPublicHoliday($date)) {
            return false;
        }

        $day = $this->scheduleDayFor($user, $date);

        // ไม่มีตารางเวลาเลย = ใช้พฤติกรรมเดิมของระบบ คือจันทร์–ศุกร์เป็นวันทำงาน
        return $day === null ? $date->isWeekday() : $day->is_working_day;
    }

    public function isHoliday(CarbonInterface $date, ?User $user = null, ?int $orgUnitId = null): bool
    {
        return ! $this->isWorkingDay($date, $user, $orgUnitId);
    }

    /**
     * จำนวนวันทำงานระหว่างสองวัน (นับรวมทั้งวันเริ่มและวันสิ้นสุด)
     *
     * เป็นตัวเดียวกับที่โมดูลลาใช้คำนวณจำนวนวันลา
     */
    public function workingDaysBetween(
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $user = null,
        ?int $orgUnitId = null,
    ): float {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        if ($to->lt($from)) {
            return 0.0;
        }

        $days = 0;

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            if ($this->isWorkingDay($date, $user, $orgUnitId)) {
                $days++;
            }
        }

        return (float) $days;
    }

    /** จำนวนวันตามปฏิทิน ไม่สนใจวันหยุด — ใช้กับการลาที่นับวันติดต่อกัน */
    public function calendarDaysBetween(CarbonInterface $start, CarbonInterface $end): float
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        return $to->lt($from) ? 0.0 : (float) ($from->diffInDays($to) + 1);
    }

    // ---------------------------------------------------------------- กะทำงาน

    /**
     * ช่วงเวลาทำงานจริงของผู้ใช้ในวันนั้น
     *
     * รองรับกะข้ามวัน: ถ้าเวลาสิ้นสุดไม่มากกว่าเวลาเริ่ม ให้ถือว่าจบในวันถัดไป
     * เช่น กะดึก 22:00 → 06:00 ของวันรุ่งขึ้น
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, crosses_midnight: bool, break_minutes: int}|null
     */
    public function shiftWindow(?User $user, CarbonInterface $date): ?array
    {
        $day = $this->scheduleDayFor($user, $date);

        if ($day === null || ! $day->is_working_day || blank($day->start_time) || blank($day->end_time)) {
            return null;
        }

        $timezone = $this->timezoneFor($user, $date);
        $base = CarbonImmutable::parse($date->toDateString(), $timezone)->startOfDay();

        $start = $this->applyTime($base, (string) $day->start_time);
        $end = $this->applyTime($base, (string) $day->end_time);

        $overnight = $day->isOvernight();

        if ($overnight) {
            $end = $end->addDay();
        }

        return [
            'start' => $start,
            'end' => $end,
            'crosses_midnight' => $overnight,
            'break_minutes' => (int) $day->break_minutes,
        ];
    }

    /** ชั่วโมงทำงานตามตารางของวันนั้น (หักเวลาพัก) */
    public function scheduledWorkMinutes(?User $user, CarbonInterface $date): int
    {
        $window = $this->shiftWindow($user, $date);

        if ($window === null) {
            return 0;
        }

        return max(0, (int) $window['start']->diffInMinutes($window['end']) - $window['break_minutes']);
    }

    // ---------------------------------------------------- มาสาย / กลับก่อนเวลา

    /**
     * จำนวนนาทีที่มาสาย — 0 ถ้าไม่สายหรือไม่ใช่วันทำงาน
     *
     * 06-3: หักเวลาผ่อนผัน (`grace_minutes`) ก่อนเสมอ
     * ของเดิมถือว่าสายทันทีที่เกินเวลาเริ่มงาน 1 วินาที และไม่รู้จักวันหยุด
     */
    public function lateMinutes(?User $user, CarbonInterface $checkInAt, ?CarbonInterface $forDate = null): int
    {
        $moment = CarbonImmutable::parse($checkInAt);
        $date = $forDate ?? $moment;

        if (! $this->isWorkingDay($date, $user)) {
            return 0;
        }

        $window = $this->shiftWindow($user, $date);

        if ($window === null) {
            return 0;
        }

        $grace = (int) ($this->scheduleFor($user, $date)?->grace_minutes ?? 0);
        $deadline = $window['start']->addMinutes($grace);
        $moment = $moment->setTimezone($window['start']->timezone);

        return $moment->lte($deadline) ? 0 : (int) $deadline->diffInMinutes($moment);
    }

    /**
     * จำนวนนาทีที่กลับก่อนเวลา — 0 ถ้าไม่ได้กลับก่อนหรือไม่ใช่วันทำงาน
     */
    public function earlyLeaveMinutes(?User $user, CarbonInterface $checkOutAt, ?CarbonInterface $forDate = null): int
    {
        $moment = CarbonImmutable::parse($checkOutAt);
        $date = $forDate ?? $moment;

        if (! $this->isWorkingDay($date, $user)) {
            return 0;
        }

        $window = $this->shiftWindow($user, $date);

        if ($window === null) {
            return 0;
        }

        $grace = (int) ($this->scheduleFor($user, $date)?->early_leave_grace_minutes ?? 0);
        $earliest = $window['end']->subMinutes($grace);
        $moment = $moment->setTimezone($window['end']->timezone);

        return $moment->gte($earliest) ? 0 : (int) $moment->diffInMinutes($earliest);
    }

    /**
     * ผู้ใช้ขาดงานในวันนั้นหรือไม่
     *
     * ขาดงาน = เป็นวันทำงานตามตาราง แต่ไม่มีการลงเวลาเลย
     * ผู้เรียกส่ง `$hasAttendance` มาเพื่อไม่ให้ service ผูกกับ schema ของ attendance
     */
    public function isAbsent(?User $user, CarbonInterface $date, bool $hasAttendance, bool $onApprovedLeave = false): bool
    {
        if ($hasAttendance || $onApprovedLeave) {
            return false;
        }

        return $this->isWorkingDay($date, $user) && $this->shiftWindow($user, $date) !== null;
    }

    // ------------------------------------------------------------ ปีงบประมาณ

    /**
     * ปีงบประมาณที่ครอบวันที่นี้
     *
     * อ่านจากตาราง `fiscal_years` ที่มีอยู่แล้ว ไม่ได้สร้างระบบปีงบใหม่
     */
    public function fiscalYear(CarbonInterface $date): ?FiscalYear
    {
        return FiscalYear::whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();
    }

    /**
     * เลข ค.ศ. ของปีที่ปีงบประมาณสิ้นสุด — คีย์เดียวกับที่ `leave_balances.year` ใช้
     *
     * `fiscal_years.year` เก็บเป็น พ.ศ. จึงต้องลบ 543 ก่อนใช้เสมอ
     * ถ้าไม่มีแถวครอบวันนั้น ให้ถอยไปใช้กฎ "ปีงบเริ่ม 1 ตุลาคม" ตามระเบียบราชการไทย
     */
    public function fiscalYearNumber(CarbonInterface $date): int
    {
        $fiscalYear = $this->fiscalYear($date);

        if ($fiscalYear !== null) {
            $year = (int) $fiscalYear->year;

            return $year > 2400 ? $year - 543 : $year;
        }

        return $date->month >= 10 ? $date->year + 1 : $date->year;
    }

    /** ช่วงวันของปีงบประมาณที่ครอบวันที่นี้ — null ถ้ายังไม่ได้ตั้งค่าปีงบ */
    public function fiscalYearRange(CarbonInterface $date): ?array
    {
        $fiscalYear = $this->fiscalYear($date);

        if ($fiscalYear === null) {
            return null;
        }

        return [
            'start' => CarbonImmutable::parse($fiscalYear->start_date)->startOfDay(),
            'end' => CarbonImmutable::parse($fiscalYear->end_date)->endOfDay(),
        ];
    }

    /** สองวันนี้อยู่คนละปีงบประมาณหรือไม่ — ใช้กับการลาคร่อมปีงบ */
    public function crossesFiscalYear(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->fiscalYearNumber($start) !== $this->fiscalYearNumber($end);
    }

    // ------------------------------------------------------------------ ภายใน

    private function applyTime(CarbonImmutable $base, string $time): CarbonImmutable
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $base->setTime($hour, $minute, $second);
    }

    /** ล้าง cache — ใช้ในเทสต์และหลังแก้ตารางเวลาภายใน request เดียวกัน */
    public function flush(): void
    {
        $this->schedules->flush();
        $this->calendar->flush();
    }
}
