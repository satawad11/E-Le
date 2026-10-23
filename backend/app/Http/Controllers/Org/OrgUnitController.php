<?php

namespace App\Http\Controllers\Org;

use App\Authz\PermissionEngine;
use App\Authz\Workflow\ApproverChainResolver;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Personnel;
use App\Services\AuditLogger;
use App\Services\Org\OrgTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API โครงสร้างองค์กร (R03-2 · CORE-01) — ปิดแทนลบ ไม่มี endpoint ลบ
 *
 * ทุก route ประกาศ ->permission() · รายการกรองด้วย PermissionEngine::scopeQuery · การแก้ตรวจระดับหน่วย (R-04)
 */
class OrgUnitController extends Controller
{
    public function __construct(private readonly OrgTree $tree, private readonly PermissionEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $units = $this->engine->scopeQuery($request->user(), 'org.unit.view', OrgUnit::query())
            ->with('type:id,code,name,level')->orderBy('depth')->orderBy('sort_order')->get();
        $parents = OrgUnit::whereIn('id', $units->pluck('parent_id')->filter())->pluck('uuid', 'id');
        $members = Assignment::where('kind', Assignment::KIND_PRIMARY)->effectiveOn(today()->toDateString())
            ->selectRaw('org_unit_id, count(*) as n')->groupBy('org_unit_id')->pluck('n', 'org_unit_id');

        return response()->json($units->map(fn (OrgUnit $u) => $this->present($u) + [
            'parent_uuid' => $parents[$u->parent_id] ?? null,
            'head' => $this->head($u),
            // จำนวนรวมหน่วยย่อย (ตัวเลขรวม ไม่ใช่รายชื่อ — รายชื่อดูที่หน้ารายชื่อบุคลากรตาม scope)
            'members' => (int) $units->filter(fn (OrgUnit $x) => str_starts_with($x->path, $u->path))->sum(fn (OrgUnit $x) => $members[$x->id] ?? 0),
        ])->values());
    }

    /**
     * รายละเอียดหน่วย (ต้นแบบหน้า 6): หัวหน้า (รวมรักษาการ) · หน่วยย่อย · สายอนุมัติที่ ApproverChainResolver คำนวณจริงจากต้นไม้
     */
    public function show(Request $request, OrgUnit $unit, ApproverChainResolver $resolver): JsonResponse
    {
        $this->authorizeResource('org.unit.view', $unit);
        $unit->load('type');

        $ancestors = $this->tree->ancestors($unit);
        $children = OrgUnit::with('type')->where('parent_id', $unit->id)->whereNull('effective_to')->orderBy('sort_order')->get();
        $steps = ApproverChainResolver::defaultStepsFor($unit);
        $chain = $resolver->resolveForUnit($unit, $steps);

        $chainOut = [];
        foreach ($steps as $index => $step) {
            $resolved = collect($chain['steps'])->first(fn (array $s) => in_array($index, $s['merged_steps'], true));
            $skipped = collect($chain['skipped'])->first(fn (array $s) => $s['index'] === $index);
            $levelUnit = $this->tree->nearestOfLevel($unit, (int) $step['level']);
            $chainOut[] = [
                'step' => $index + 1,
                'level' => $step['level'],
                'unit' => $levelUnit?->name,
                'approvers' => $resolved ? collect($resolved['approvers'])->map(fn ($a) => [
                    'name' => Personnel::whereKey($a['personnel_id'])->first()?->full_name,
                    'position' => $a['position'],
                    'acting' => $a['acting'],
                ])->unique('name')->values() : [],
                'merged_with_previous' => $resolved !== null && $resolved['merged_steps'][0] !== $index,
                'skipped' => $skipped !== null,
                'skip_reason' => $skipped['reason'] ?? null,
            ];
        }

        return response()->json($this->present($unit) + [
            'path' => $ancestors->map(fn (OrgUnit $u) => $u->name)->reverse()->push($unit->name)->values(),
            'head' => $this->head($unit),
            'children' => $children->map(fn (OrgUnit $c) => $this->present($c) + ['head' => $this->head($c)])->values(),
            'approval_chain' => $chainOut,
            'chain_unresolved' => $chain['unresolved'],
            'can' => ['manage' => $this->engine->allows($request->user(), 'org.unit.manage', $unit)],
        ]);
    }

    /** @return array{name: ?string, position: ?string, acting: bool, effective_to: ?string}|null */
    private function head(OrgUnit $unit): ?array
    {
        $head = $this->tree->headOf($unit);
        if ($head === null) {
            return null;
        }
        $head->loadMissing(['personnel', 'position', 'actingFor.position']);

        return [
            'name' => $head->personnel?->full_name,
            'position' => $head->kind === Assignment::KIND_ACTING ? $head->actingFor?->position?->name : $head->position?->name,
            'acting' => $head->kind === Assignment::KIND_ACTING,
            'effective_to' => $head->effective_to?->toDateString(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'unique:org_units,code'],
            'name' => ['required', 'string', 'max:255'],
            'type_code' => ['required', 'exists:org_unit_types,code'],
            'parent_uuid' => ['nullable', 'uuid', 'exists:org_units,uuid'],
            'effective_from' => ['required', 'date'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $unit = $this->tree->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'org_unit_type_id' => OrgUnitType::where('code', $data['type_code'])->value('id'),
            'effective_from' => $data['effective_from'],
            'sort_order' => $data['sort_order'] ?? 0,
        ], isset($data['parent_uuid']) ? tap(OrgUnit::where('uuid', $data['parent_uuid'])->firstOrFail(), fn (OrgUnit $p) => $this->authorizeResource('org.unit.manage', $p)) : null, $request->user()->id);

        return response()->json($this->present($unit->load('type')), 201);
    }

    public function update(Request $request, OrgUnit $unit): JsonResponse
    {
        $this->authorizeResource('org.unit.manage', $unit);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
        ]);
        $before = $unit->only(array_keys($data));
        $unit->update($data);
        app(AuditLogger::class)->log('org_unit.updated', $unit, null, $before, $unit->only(array_keys($data)));

        return response()->json($this->present($unit->load('type')));
    }

    public function move(Request $request, OrgUnit $unit): JsonResponse
    {
        $this->authorizeResource('org.unit.manage', $unit);
        $data = $request->validate(['parent_uuid' => ['required', 'uuid', 'exists:org_units,uuid']]);
        $parent = OrgUnit::where('uuid', $data['parent_uuid'])->firstOrFail();
        $this->authorizeResource('org.unit.manage', $parent);
        $unit = $this->tree->move($unit, $parent, $request->user()->id);

        return response()->json($this->present($unit->load('type')));
    }

    public function close(Request $request, OrgUnit $unit): JsonResponse
    {
        $this->authorizeResource('org.unit.manage', $unit);
        $data = $request->validate(['effective_to' => ['required', 'date']]);

        return response()->json($this->present($this->tree->close($unit, $data['effective_to'], $request->user()->id)->load('type')));
    }

    /** @return array<string, mixed> */
    private function present(OrgUnit $unit): array
    {
        return [
            'uuid' => $unit->uuid,
            'code' => $unit->code,
            'name' => $unit->name,
            'type' => ['code' => $unit->type->code, 'name' => $unit->type->name, 'level' => $unit->type->level],
            'depth' => $unit->depth,
            'sort_order' => $unit->sort_order,
            'effective_from' => $unit->effective_from?->toDateString(),
            'effective_to' => $unit->effective_to?->toDateString(),
        ];
    }
}
