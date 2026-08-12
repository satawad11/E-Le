<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkCalendarException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkCalendarController extends Controller
{
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

        return response()->json(WorkCalendarException::updateOrCreate(
            ['date' => $data['date'], 'department_id' => $data['department_id'] ?? null],
            $data
        )->load('department:id,name'), 201);
    }

    public function update(Request $request, WorkCalendarException $calendarException)
    {
        $data = $this->validated($request, true);
        $calendarException->update($data);

        return $calendarException->fresh()->load('department:id,name');
    }

    public function destroy(WorkCalendarException $calendarException)
    {
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
