<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrgScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * หน่วยงานในผังองค์กร
 *
 * **ที่มา:** ระบบเคยมีโครงหน่วยงานสองชุด — `departments` แบบชั้นเดียวที่มีหน้าจอให้
 * จัดการ กับ `org_units` แบบหลายชั้นที่การอนุมัติ ขอบเขตการมองเห็น ปฏิทิน และการหา
 * หัวหน้าใช้จริงแต่ **ไม่เคยมีหน้าจอให้จัดการเลย** · หน่วยงานในผังจึงเกิดได้ทางเดียว
 * คือเป็นผลพลอยได้ตอนสร้าง "แผนกวิชา" ซึ่งแขวนไว้ใต้ยอดผังเสมอ ผังจึงลึกเกินสองชั้น
 * ไม่ได้เลยตลอดอายุระบบ
 *
 * คลาสนี้คือหน้าจัดการผังจริง และเป็นที่ที่หน้า "จัดการแผนกวิชา" เดิมย้ายมาอยู่
 */
class OrgUnitController extends Controller
{
    public function __construct(private readonly OrgScopeResolver $orgScope) {}

    /** ผังทั้งหมดพร้อมจำนวนคนในแต่ละหน่วย — เรียงตามลำดับชั้น */
    public function index()
    {
        $counts = DB::table('users')->where('is_active', true)
            ->whereNotNull('org_unit_id')
            ->groupBy('org_unit_id')
            ->pluck(DB::raw('count(*)'), 'org_unit_id');

        $units = OrgUnit::query()->orderBy('parent_id')->orderBy('name')
            ->get(['id', 'parent_id', 'code', 'name', 'type', 'is_active', 'effective_from']);

        return response()->json(
            $units->map(fn (OrgUnit $unit) => [
                ...$unit->only(['id', 'parent_id', 'code', 'name', 'type', 'is_active']),
                'effective_from' => $unit->effective_from?->toDateString(),
                'members' => (int) ($counts[$unit->id] ?? 0),
                // ยอดผังแทนตัวสถานศึกษา ลบไม่ได้และไม่ควรย้าย
                'is_root' => $unit->parent_id === null,
            ])
        );
    }

    /** รายชื่อหน่วยงานสำหรับช่องตัวกรอง — ผู้ใช้ที่ล็อกอินแล้วเรียกได้ทุกคน */
    public function options()
    {
        return response()->json(
            OrgUnit::query()->where('is_active', true)
                ->orderBy('parent_id')->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
        );
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $data = $this->validated($request);

        $unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $data['parent_id'] ?? $this->rootId(),
            'code' => $data['code'] ?? $this->generateCode($data['name']),
            'name' => $data['name'],
            'type' => $data['type'] ?? 'department',
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
            'is_active' => $data['is_active'] ?? true,
        ]);

        $audit->log('org_unit.created', $unit, after: $unit->only(['name', 'parent_id', 'type']));

        return response()->json(['message' => 'สร้างหน่วยงานเรียบร้อยแล้ว', 'org_unit' => $unit], 201);
    }

    public function update(Request $request, OrgUnit $orgUnit, AuditLogger $audit)
    {
        $data = $this->validated($request, $orgUnit);
        $before = $orgUnit->only(['name', 'parent_id', 'type', 'is_active']);

        $orgUnit->update(array_filter([
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
        ], fn ($value) => $value !== null) + [
            'is_active' => $data['is_active'] ?? $orgUnit->is_active,
        ]);

        $audit->log('org_unit.updated', $orgUnit, before: $before, after: $orgUnit->only(['name', 'parent_id', 'type', 'is_active']));

        return response()->json(['message' => 'แก้ไขหน่วยงานเรียบร้อยแล้ว', 'org_unit' => $orgUnit->fresh()]);
    }

    /**
     * ลบหน่วยงาน — ทำได้เฉพาะหน่วยที่ยังไม่มีอะไรอ้างถึง
     *
     * หน่วยงานถูกอ้างจากผู้ใช้ ผู้รับผิดชอบ ปฏิทิน และหน่วยงานลูก · การลบทั้งที่ยังมี
     * ผู้อ้างจะทำให้ใบลาเก่าอธิบายไม่ได้ว่าผู้ยื่นสังกัดที่ใด จึงปฏิเสธพร้อมบอกจำนวน
     * ที่ต้องย้ายก่อน แทนที่จะปล่อยให้ฐานข้อมูลตัดสินด้วย foreign key error
     */
    public function destroy(OrgUnit $orgUnit, AuditLogger $audit)
    {
        abort_if($orgUnit->parent_id === null, 422, 'ยอดผังองค์กรลบไม่ได้ เพราะแทนตัวสถานศึกษาเอง');

        $blockers = [
            'ผู้ใช้ที่สังกัดอยู่' => User::where('org_unit_id', $orgUnit->id)->count(),
            'หน่วยงานลูก' => OrgUnit::where('parent_id', $orgUnit->id)->count(),
            'การมอบหมายผู้รับผิดชอบ' => DB::table('org_unit_managers')->where('org_unit_id', $orgUnit->id)->count(),
            'ประกาศวันหยุดเฉพาะหน่วยงาน' => DB::table('work_calendar_exceptions')->where('org_unit_id', $orgUnit->id)->count(),
        ];

        $found = collect($blockers)->filter()->map(fn (int $count, string $label) => "{$label} {$count} รายการ");

        abort_if(
            $found->isNotEmpty(),
            422,
            'ลบหน่วยงานนี้ไม่ได้เพราะยังมี '.$found->implode(' · ').' — ย้ายออกก่อน หรือปิดการใช้งานหน่วยงานแทนการลบ',
        );

        $audit->log('org_unit.deleted', 'OrgUnit', $orgUnit->id, before: $orgUnit->only(['name', 'parent_id']));
        $orgUnit->delete();

        return response()->json(['message' => 'ลบหน่วยงานเรียบร้อยแล้ว']);
    }

    public function toggleStatus(OrgUnit $orgUnit, AuditLogger $audit)
    {
        abort_if($orgUnit->parent_id === null, 422, 'ยอดผังองค์กรปิดการใช้งานไม่ได้');

        $orgUnit->update(['is_active' => ! $orgUnit->is_active]);
        $audit->log('org_unit.status_changed', $orgUnit, after: ['is_active' => $orgUnit->is_active]);

        return response()->json([
            'message' => $orgUnit->is_active ? 'เปิดใช้งานหน่วยงานแล้ว' : 'ปิดใช้งานหน่วยงานแล้ว',
            'org_unit' => $orgUnit,
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?OrgUnit $current = null): array
    {
        $data = $request->validate([
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:30'],
            'parent_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'effective_from' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'กรุณากรอกชื่อหน่วยงาน',
            'parent_id.exists' => 'ไม่พบหน่วยงานแม่ที่เลือก',
        ]);

        /*
         * ผังต้องเป็นต้นไม้เสมอ — ย้ายหน่วยงานไปอยู่ใต้ลูกหลานของตัวเองจะเกิดวงวน
         * ซึ่งทำให้การไล่หาหัวหน้าและการคิดขอบเขตการมองเห็นวนไม่รู้จบ
         */
        if ($current !== null && ($data['parent_id'] ?? null) !== null) {
            abort_if(
                in_array((int) $data['parent_id'], $this->orgScope->subtreeIds($current->id), true),
                422,
                'ย้ายหน่วยงานไปอยู่ใต้ตัวเองหรือหน่วยงานลูกของตัวเองไม่ได้',
            );
        }

        return $data;
    }

    private function rootId(): ?int
    {
        return OrgUnit::whereNull('parent_id')->value('id');
    }

    /** รหัสหน่วยงานที่ไม่ซ้ำ — ผู้ดูแลไม่ต้องคิดเอง */
    private function generateCode(string $name): string
    {
        $base = 'ORG-'.Str::upper(Str::random(6));

        while (OrgUnit::where('code', $base)->exists()) {
            $base = 'ORG-'.Str::upper(Str::random(6));
        }

        return $base;
    }
}
