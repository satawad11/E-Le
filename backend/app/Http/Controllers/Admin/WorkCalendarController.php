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
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
        ]);

        return WorkCalendarException::with('orgUnit:id,uuid,name')
            ->when($validated['year'] ?? null, fn ($query, $year) => $query->whereYear('date', $year))
            ->when(array_key_exists('org_unit_id', $validated), fn ($query) => $query->where('org_unit_id', $validated['org_unit_id']))
            ->orderBy('date')->paginate(100);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(WorkCalendarException::updateOrCreate(
            ['date' => $data['date'], 'org_unit_id' => $data['org_unit_id'] ?? null],
            $data
        )->load('orgUnit:id,uuid,name'), 201);
    }

    public function update(Request $request, WorkCalendarException $calendarException)
    {
        $data = $this->validated($request, true);
        $calendarException->update($data);

        return $calendarException->fresh()->load('orgUnit:id,uuid,name');
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
            'org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'day_type' => [$sometimes, Rule::in(['holiday', 'working_day'])],
            'name' => [$sometimes, 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
