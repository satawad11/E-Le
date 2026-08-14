<?php

namespace App\Services\Time;

use App\Models\Holiday;
use App\Models\WorkCalendarException;
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
     * คืน true = บังคับเป็นวันทำงาน · false = บังคับเป็นวันหยุด · null = ไม่มีข้อยกเว้น
     */
    public function calendarException(CarbonInterface $date, ?int $departmentId = null): ?bool
    {
        $exception = WorkCalendarException::query()
            ->whereDate('date', $date->toDateString())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('department_id')
                ->when($departmentId, fn ($inner) => $inner->orWhere('department_id', $departmentId)))
            // ข้อยกเว้นของหน่วยงานตัวเองชนะข้อยกเว้นระดับองค์กร
            ->orderByRaw('CASE WHEN department_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        return $exception === null ? null : (bool) $exception->is_working_day;
    }

    /**
     * ล้าง cache — ใช้ในเทสต์หรือหลังแก้ข้อมูลปฏิทินภายใน request เดียวกัน
     */
    public function flush(): void
    {
        $this->holidayCache = [];
    }
}
