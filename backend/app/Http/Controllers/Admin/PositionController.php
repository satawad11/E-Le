<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ทะเบียนตำแหน่งและกลุ่มบุคลากร (ORG-ACCESS-02)
 *
 * - อ่านได้ทุกคนที่เข้าสู่ระบบ (ข้อมูลอ้างอิง ไม่ใช่ข้อมูลส่วนบุคคล) ใช้ในฟอร์มผู้ใช้และตัวกรองรายงาน
 * - เพิ่ม/แก้ไขต้องมี `system.user.manage` (ตรวจที่ route) และบันทึก audit ทุกครั้ง
 * - ไม่มีการลบ — ปิดใช้งานแทน เพราะประวัติการดำรงตำแหน่งยังอ้างถึงอยู่
 */
class PositionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $groups = PositionGroup::query()
            ->with(['positions' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($groups->map(fn (PositionGroup $group) => $this->groupPayload($group)));
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', 'unique:position_groups,code'],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $group = DB::transaction(function () use ($data) {
            $group = PositionGroup::create($data + ['is_active' => true]);
            $this->audit->log('position_group.created', 'position_group', $group->getKey(), null, $group->only(['uuid', 'code', 'name', 'sort_order', 'is_active']));

            return $group;
        });

        return response()->json($this->groupPayload($group->load('positions')), 201);
    }

    public function updateGroup(Request $request, PositionGroup $positionGroup): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('position_groups', 'code')->ignore($positionGroup->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $data['is_active'] && $positionGroup->positions()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => 'ปิดใช้งานกลุ่มไม่ได้ เพราะยังมีตำแหน่งที่ใช้งานอยู่ในกลุ่มนี้ กรุณาปิดใช้งานหรือย้ายตำแหน่งก่อน']);
        }

        DB::transaction(function () use ($positionGroup, $data) {
            $before = $positionGroup->only(['uuid', 'code', 'name', 'sort_order', 'is_active']);
            $positionGroup->update($data);
            $this->audit->log('position_group.updated', 'position_group', $positionGroup->getKey(), $before, $positionGroup->only(['uuid', 'code', 'name', 'sort_order', 'is_active']));
        });

        return response()->json($this->groupPayload($positionGroup->load('positions')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePosition($request);

        $position = DB::transaction(function () use ($data) {
            $position = Position::create($data + ['is_active' => true]);
            $this->audit->log('position.created', 'position', $position->getKey(), null, $this->auditSnapshot($position));

            return $position;
        });

        return response()->json($this->positionPayload($position->load('group')), 201);
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        $data = $this->validatePosition($request, $position);

        DB::transaction(function () use ($position, $data) {
            $before = $this->auditSnapshot($position);
            $position->update($data);
            $this->audit->log('position.updated', 'position', $position->getKey(), $before, $this->auditSnapshot($position));
        });

        return response()->json($this->positionPayload($position->load('group')));
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePosition(Request $request, ?Position $position = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('positions', 'code')->ignore($position?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'group_uuid' => ['required', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => [$position ? 'required' : 'prohibited', 'boolean'],
        ]);

        $group = PositionGroup::where('uuid', $data['group_uuid'])->first();
        if ($group === null || ! $group->is_active) {
            throw ValidationException::withMessages(['group_uuid' => 'ไม่พบกลุ่มบุคลากรที่ใช้งานอยู่']);
        }

        unset($data['group_uuid']);

        return $data + ['position_group_id' => $group->getKey()];
    }

    /**
     * @return array<string,mixed>
     */
    private function auditSnapshot(Position $position): array
    {
        return $position->only(['uuid', 'code', 'name', 'position_group_id', 'sort_order', 'is_active']);
    }

    /**
     * @return array<string,mixed>
     */
    private function groupPayload(PositionGroup $group): array
    {
        return [
            'uuid' => $group->uuid,
            'code' => $group->code,
            'name' => $group->name,
            'sort_order' => $group->sort_order,
            'is_active' => $group->is_active,
            'positions' => $group->positions->map(fn (Position $position) => [
                'uuid' => $position->uuid,
                'code' => $position->code,
                'name' => $position->name,
                'sort_order' => $position->sort_order,
                'is_active' => $position->is_active,
            ])->values(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function positionPayload(Position $position): array
    {
        return [
            'uuid' => $position->uuid,
            'code' => $position->code,
            'name' => $position->name,
            'sort_order' => $position->sort_order,
            'is_active' => $position->is_active,
            'group' => ['uuid' => $position->group->uuid, 'name' => $position->group->name],
        ];
    }
}
