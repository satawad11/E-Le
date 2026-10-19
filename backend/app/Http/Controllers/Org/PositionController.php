<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\OrgUnitType;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API ตำแหน่ง (R03-2 · CORE-01) — ไม่ลบ ใช้ปิดใช้งาน · ตำแหน่งบริหารประเภทหน่วยละหนึ่งตำแหน่ง
 */
class PositionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Position::with(['group:id,uuid,code,name', 'orgUnitType:id,code,name,level'])
            ->orderBy('kind')->orderBy('sort_order')->get()
            ->map(fn (Position $p) => $this->present($p))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:positions,code'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([Position::KIND_LINE, Position::KIND_MANAGEMENT])],
            'group_uuid' => ['nullable', 'uuid', 'exists:position_groups,uuid'],
            'org_unit_type_code' => ['nullable', 'required_if:kind,management', 'prohibited_if:kind,line', 'exists:org_unit_types,code'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $typeId = isset($data['org_unit_type_code']) ? OrgUnitType::where('code', $data['org_unit_type_code'])->value('id') : null;
        if ($typeId !== null && Position::where('org_unit_type_id', $typeId)->exists()) {
            return response()->json(['message' => 'ประเภทหน่วยนี้มีตำแหน่งบริหารอยู่แล้ว', 'errors' => ['org_unit_type_code' => ['ประเภทหน่วยละหนึ่งตำแหน่ง']]], 422);
        }

        $position = Position::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'position_group_id' => isset($data['group_uuid']) ? PositionGroup::where('uuid', $data['group_uuid'])->value('id') : null,
            'org_unit_type_id' => $typeId,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        app(AuditLogger::class)->log('position.created', $position, null, null, $position->only(['code', 'name', 'kind', 'org_unit_type_id']));

        return response()->json($this->present($position->load(['group', 'orgUnitType'])), 201);
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $position->only(array_keys($data));
        $position->update($data);
        app(AuditLogger::class)->log('position.updated', $position, null, $before, $position->only(array_keys($data)));

        return response()->json($this->present($position->load(['group', 'orgUnitType'])));
    }

    /** @return array<string, mixed> */
    private function present(Position $position): array
    {
        return [
            'uuid' => $position->uuid,
            'code' => $position->code,
            'name' => $position->name,
            'kind' => $position->kind,
            'group' => $position->group?->only(['uuid', 'code', 'name']),
            'org_unit_type' => $position->orgUnitType?->only(['code', 'name', 'level']),
            'is_active' => $position->is_active,
        ];
    }
}
