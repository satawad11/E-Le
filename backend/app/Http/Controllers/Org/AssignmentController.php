<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\Position;
use App\Services\Personnel\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API คำสั่งแต่งตั้งและมอบหมาย (R03-4 · CORE-02) — เพิ่มและสิ้นสุดเท่านั้น ไม่มีแก้/ลบ (ห้ามเขียนทับ)
 */
class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $service) {}

    public function index(Personnel $personnel): JsonResponse
    {
        return response()->json($personnel->assignments()->with(['orgUnit:id,uuid,name', 'position:id,uuid,name,kind', 'actingFor:id,uuid'])
            ->orderByDesc('effective_from')->get()->map(fn (Assignment $a) => $this->present($a))->values());
    }

    public function store(Request $request, Personnel $personnel): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Assignment::KINDS)],
            'org_unit_uuid' => ['required_unless:kind,acting', 'nullable', 'uuid', 'exists:org_units,uuid'],
            'position_uuid' => ['required_unless:kind,acting', 'nullable', 'uuid', 'exists:positions,uuid'],
            'acting_for_uuid' => ['required_if:kind,acting', 'nullable', 'uuid', 'exists:assignments,uuid'],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $assignment = $this->service->add($personnel, [
            'kind' => $data['kind'],
            'org_unit_id' => isset($data['org_unit_uuid']) ? OrgUnit::where('uuid', $data['org_unit_uuid'])->value('id') : null,
            'position_id' => isset($data['position_uuid']) ? Position::where('uuid', $data['position_uuid'])->value('id') : null,
            'acting_for_assignment_id' => isset($data['acting_for_uuid']) ? Assignment::where('uuid', $data['acting_for_uuid'])->value('id') : null,
            'order_number' => $data['order_number'],
            'order_date' => $data['order_date'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
        ], $request->user()->id);

        return response()->json($this->present($assignment->load(['orgUnit', 'position', 'actingFor'])), 201);
    }

    public function end(Request $request, Assignment $assignment): JsonResponse
    {
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
            'end_reason' => ['required', 'string', 'max:255'],
        ]);

        $assignment = $this->service->end($assignment, $data['effective_to'], $data['end_reason'], $request->user()->id);

        return response()->json($this->present($assignment->load(['orgUnit', 'position', 'actingFor'])));
    }

    /** @return array<string, mixed> */
    private function present(Assignment $a): array
    {
        return [
            'uuid' => $a->uuid,
            'kind' => $a->kind,
            'org_unit' => $a->orgUnit?->only(['uuid', 'name']),
            'position' => $a->position?->only(['uuid', 'name', 'kind']),
            'acting_for_uuid' => $a->actingFor?->uuid,
            'order_number' => $a->order_number,
            'order_date' => $a->order_date?->toDateString(),
            'effective_from' => $a->effective_from->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(),
            'end_reason' => $a->end_reason,
        ];
    }
}
