<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkCalendarException;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * วันหยุด/วันทำการพิเศษของหน่วยงาน — กระทบการนับวันลาของทุกคน ทุกการแก้จึงต้อง audit
 */
class WorkCalendarController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2200'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return WorkCalendarException::with('department:id,name')
            ->when($validated['year'] ?? null, fn ($query, $year) => $query->whereYear('date', $year))
            ->when(array_key_exists('department_id', $validated), fn ($query) => $query->where('department_id', $validated['department_id']))
            ->orderBy('date')->paginate(100);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $key = ['date' => $data['date'], 'department_id' => $data['department_id'] ?? null];
        $before = WorkCalendarException::where($key)->first()?->toArray();
        $exception = WorkCalendarException::updateOrCreate($key, $data);
        $this->audit->log($before ? 'work_calendar.updated' : 'work_calendar.created', $exception, $exception->id, $before, $exception->fresh()->toArray());

        return response()->json($exception->load('department:id,name'), 201);
    }

    public function update(Request $request, WorkCalendarException $calendarException)
    {
        $data = $this->validated($request, true);
        $before = $calendarException->toArray();
        $calendarException->update($data);
        $this->audit->log('work_calendar.updated', $calendarException, $calendarException->id, $before, $calendarException->fresh()->toArray());

        return $calendarException->fresh()->load('department:id,name');
    }

    public function destroy(WorkCalendarException $calendarException)
    {
        $this->audit->log('work_calendar.deleted', $calendarException, $calendarException->id, $calendarException->toArray(), null);
        $calendarException->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'date' => [$sometimes, 'date'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'day_type' => [$sometimes, Rule::in(['holiday', 'working_day'])],
            'name' => [$sometimes, 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
