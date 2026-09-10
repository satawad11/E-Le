<?php

namespace App\Services\Time;

use App\Models\Holiday;
use App\Models\WorkCalendarException;
use App\Services\OrgScopeResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * LEGACY-06 · 06-4 — ปฏิทินวันทำงานกลาง
 *
 * **ไม่ได้สร้างตารางใหม่** — `holidays` และ `work_calendar_exceptions` ถูกประเมินแล้วว่า
 * ใช้ได้ดี (TIME-CAL-001 = PASS) คลาสนี้เพียงรวมตรรกะการอ่านทั้งสองตารางไว้ที่เดียว
 *
 * เดิมตรรกะนี้อยู่ใน `LeaveService::calculateTotalDays()` จุดเดียว ส่วนโมดูล
 * attendance ไม่รู้จักวันหยุดเลย — การนับ "มาสาย" จึงเกิดขึ้นได้แม้ในวันหยุด
 *
 * ลำดับการตัดสินว่าวันหนึ่งเป็นวันทำงานหรือไม่:
 *   1. `work_calendar_exceptions` ของหน่วยงานนั้นโดยตรง  (ชนะเสมอ)
 *   2. `work_calendar_exceptions` ระดับองค์กร (department_id = null)
 *   3. `holidays` — วันหยุดราชการ
 *   4. ตารางเวลาทำงาน (เสาร์อาทิตย์ปกติไม่ใช่วันทำงาน)
 */
class WorkingCalendar
{
    /** @var array<string,array<string,bool>> cache ต่อ request */
    private array $holidayCache = [];

    public function __construct(private readonly OrgScopeResolver $orgScope) {}

    /**
     * วันนี้เป็นวันหยุดราชการหรือไม่ (ยังไม่รวม exception รายหน่วยงาน)
     */
    public function isPublicHoliday(CarbonInterface $date): bool
    {
        $key = $date->toDateString();

        $this->holidayCache['public'] ??= Holiday::where('is_active', true)
            ->pluck('date')
            ->mapWithKeys(fn ($value) => [CarbonImmutable::parse($value)->toDateString() => true])
            ->all();

        return isset($this->holidayCache['public'][$key]);
    }

    /**
     * ข้อยกเว้นปฏิทินที่มีผลกับหน่วยงานนี้ในวันนี้
     *
     * ประกาศที่หน่วยงานแม่มีผลถึงหน่วยงานลูกด้วย — ผู้ดูแลประกาศวันหยุดของ
     * "ฝ่ายวิชาการ" ครั้งเดียวแล้วมีผลกับทุกงานใต้ฝ่ายนั้น · ประกาศที่หน่วยงาน
     * เจาะจงกว่าชนะเสมอ และประกาศระดับองค์กร (`org_unit_id` เป็น null) เป็นชั้นล่างสุด
     *
     * คืน true = บังคับเป็นวันทำงาน · false = บังคับเป็นวันหยุด · null = ไม่มีข้อยกเว้น
     */
    public function calendarException(CarbonInterface $date, ?int $orgUnitId = null): ?bool
    {
        // ตัวเองอยู่หน้าสุด ไล่ขึ้นไปหายอดผัง — ลำดับนี้คือลำดับความเฉพาะเจาะจง
        $chain = $orgUnitId === null ? [] : $this->orgScope->ancestorIds($orgUnitId);

        $exceptions = WorkCalendarException::query()
            ->whereDate('date', $date->toDateString())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('org_unit_id')
                ->when($chain !== [], fn ($inner) => $inner->orWhereIn('org_unit_id', $chain)))
            ->get();

        if ($exceptions->isEmpty()) {
            return null;
        }

        /*
         * เรียงตามระยะห่างจากหน่วยงานของผู้ใช้ ยิ่งใกล้ยิ่งชนะ
         * ประกาศระดับองค์กรไม่มีหน่วยงาน จึงถูกจัดไว้ท้ายสุดเสมอ
         */
        $winner = $exceptions
            ->sortBy(fn (WorkCalendarException $exception) => $exception->org_unit_id === null
                ? PHP_INT_MAX
                : array_search((int) $exception->org_unit_id, $chain, true))
            ->first();

        return (bool) $winner->is_working_day;
    }

    /**
     * ล้าง cache — ใช้ในเทสต์หรือหลังแก้ข้อมูลปฏิทินภายใน request เดียวกัน
     */
    public function flush(): void
    {
        $this->holidayCache = [];
    }
}
