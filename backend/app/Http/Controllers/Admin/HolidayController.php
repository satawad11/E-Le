<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * วันหยุดราชการทั่วประเทศ (ตาราง holidays) — ใช้ตัดวันทำการตอนคำนวณวันลา
 *
 * เดิมเพิ่มได้ทาง seeder อย่างเดียว ทุกปีที่ ครม. ประกาศวันหยุดใหม่ต้องให้นักพัฒนาแก้โค้ด
 * วันหยุดเฉพาะหน่วยงานยังอยู่ที่ WorkCalendarController (work_calendar_exceptions)
 */
class HolidayController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $year = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2200']])['year'] ?? null;

        return Holiday::query()
            ->when($year, fn ($query, $year) => $query->whereYear('date', $year))
            ->orderBy('date')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date', $this->uniqueDate()],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $holiday = Holiday::create($data + ['is_active' => true]);
        $this->audit->log('holiday.created', $holiday, $holiday->id, null, $holiday->toArray());

        return response()->json($holiday, 201);
    }

    public function update(Request $request, Holiday $holiday)
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date', $this->uniqueDate($holiday->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $holiday->toArray();
        $holiday->update($data);
        $this->audit->log('holiday.updated', $holiday, $holiday->id, $before, $holiday->fresh()->toArray());

        return $holiday->fresh();
    }

    /** เทียบเฉพาะส่วนวันที่ — คอลัมน์อาจเก็บเวลา 00:00:00 ติดมาด้วย กฎ unique ปกติจึงจับซ้ำไม่ได้ */
    private function uniqueDate(?int $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
            $exists = Holiday::whereDate('date', Carbon::parse($value)->toDateString())
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();
            if ($exists) {
                $fail('มีวันหยุดราชการในวันที่นี้อยู่แล้ว');
            }
        };
    }

    public function destroy(Holiday $holiday)
    {
        $this->audit->log('holiday.deleted', $holiday, $holiday->id, $holiday->toArray(), null);
        $holiday->delete();

        return response()->noContent();
    }
}
