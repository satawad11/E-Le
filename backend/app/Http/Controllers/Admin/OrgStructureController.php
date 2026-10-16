<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\OrgUnit;
use App\Models\OrgUnitManager;
use App\Models\OrgUnitMember;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Workflow\Assignee\OvertimeAssigneeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * โครงสร้างหน่วยงานและทะเบียนหัวหน้าหน่วยงาน (ACCESS-GOV-01)
 *
 * ระเบียบ สอศ. ว่าด้วยการบริหารสถานศึกษา พ.ศ. 2569 แบ่งสถานศึกษาเป็น 4 ฝ่าย (ข้อ 8)
 * แต่ละฝ่ายมีงาน/แผนกวิชา (ข้อ 9–12) และผู้อำนวยการมอบหมายรองฯ/หัวหน้างาน/หัวหน้าแผนก (ข้อ 13, 47)
 * ชื่อฝ่ายและงานเป็นข้อมูลที่ผู้ดูแลกรอก ไม่ได้ hard-code ในระบบ
 *
 * - ไม่มีการลบทั้งหน่วยงานและการมอบหมาย: ปิดใช้งานหน่วยงาน / ปิดวาระหัวหน้าแทน
 * - หน่วยงานที่ผูกกับแผนก (departments) แก้ชื่อที่หน้าจัดการแผนก เพื่อไม่ให้สองที่ชื่อไม่ตรงกัน
 * - ทุกการเปลี่ยนแปลงบันทึก audit เพราะหัวหน้าหน่วยงานคือผู้อนุมัติใบลาของคนในหน่วย
 */
class OrgStructureController extends Controller
{
    /** ชนิดหน่วยงานที่สร้างจากหน้านี้ได้ — `organization` มีหน่วยเดียว (ROOT) และ `department` มาจากหน้าจัดการแผนก */
    public const CREATABLE_TYPES = ['division', 'section'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $linked = DB::table('department_org_unit_map')->pluck('department_id', 'org_unit_id');

        $managers = OrgUnitManager::query()
            ->with('user.profile')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->orderBy('effective_from')
            ->get()
            ->groupBy('org_unit_id');

        $members = OrgUnitMember::query()
            ->with('user.profile')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->orderBy('effective_from')
            ->get()
            ->groupBy('org_unit_id');

        $units = OrgUnit::query()->orderBy('name')->get()->map(fn (OrgUnit $unit) => [
            'id' => $unit->id,
            'uuid' => $unit->uuid,
            'parent_id' => $unit->parent_id,
            'code' => $unit->code,
            'name' => $unit->name,
            'type' => $unit->type,
            'is_active' => (bool) $unit->is_active,
            'linked_department' => $linked->has($unit->id),
            'managers' => ($managers->get($unit->id) ?? collect())->map(fn (OrgUnitManager $m) => $this->managerPayload($m, $today))->values(),
            'members' => ($members->get($unit->id) ?? collect())->map(fn (OrgUnitMember $m) => $this->memberPayload($m, $today))->values(),
        ]);

        $permitUnitId = (int) (Setting::where('key', OvertimeAssigneeResolver::PERMIT_UNIT_SETTING)->value('value') ?: 0) ?: null;

        return response()->json([
            'units' => $units,
            // หน่วยงานที่หัวหน้าเป็นผู้อนุญาตคำสั่ง OT (รองผู้อำนวยการฝ่ายบริหารทรัพยากร)
            'overtime_permit_unit_uuid' => $permitUnitId ? OrgUnit::whereKey($permitUnitId)->value('uuid') : null,
        ]);
    }

    /** ตั้งหน่วยงานที่หัวหน้าเป็นผู้อนุญาตคำสั่ง OT — null = กลับไปใช้บทบาทตามเดิม */
    public function updateOvertimePermitUnit(Request $request): JsonResponse
    {
        $data = $request->validate(['org_unit_uuid' => ['nullable', 'string', 'exists:org_units,uuid']], $this->messages());
        $unit = filled($data['org_unit_uuid'] ?? null) ? OrgUnit::where('uuid', $data['org_unit_uuid'])->firstOrFail() : null;
        abort_if($unit !== null && ! $unit->is_active, 422, 'หน่วยงานนี้ถูกปิดใช้งานแล้ว');

        $before = Setting::where('key', OvertimeAssigneeResolver::PERMIT_UNIT_SETTING)->value('value');
        DB::transaction(function () use ($unit, $before) {
            Setting::updateOrCreate(['key' => OvertimeAssigneeResolver::PERMIT_UNIT_SETTING], ['value' => (string) ($unit?->id ?? ''), 'type' => 'string']);
            $this->audit->log('org_unit.overtime_permit_unit_set', 'setting', OvertimeAssigneeResolver::PERMIT_UNIT_SETTING, ['org_unit_id' => $before], ['org_unit_id' => $unit?->id]);
        });

        return response()->json(['message' => 'บันทึกผู้อนุญาตคำสั่ง OT เรียบร้อยแล้ว']);
    }

    /** ประวัติหัวหน้าทั้งหมดของหน่วยงาน รวมวาระที่ปิดแล้ว */
    public function managerHistory(string $uuid): JsonResponse
    {
        $unit = OrgUnit::where('uuid', $uuid)->firstOrFail();
        $today = now()->toDateString();

        return response()->json(
            OrgUnitManager::with('user.profile')->where('org_unit_id', $unit->id)
                ->orderByDesc('effective_from')->get()
                ->map(fn (OrgUnitManager $m) => $this->managerPayload($m, $today))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::CREATABLE_TYPES)],
            'parent_uuid' => ['required', 'string', 'exists:org_units,uuid'],
        ], $this->messages());

        $parent = OrgUnit::where('uuid', $data['parent_uuid'])->firstOrFail();
        abort_unless($parent->is_active, 422, 'หน่วยงานแม่ถูกปิดใช้งานแล้ว');

        $unit = DB::transaction(function () use ($data, $parent) {
            $unit = OrgUnit::create([
                'uuid' => (string) Str::uuid(),
                'parent_id' => $parent->id,
                'code' => 'OU-'.Str::upper(Str::random(10)),
                'name' => trim($data['name']),
                'type' => $data['type'],
                'effective_from' => now()->toDateString(),
                'is_active' => true,
            ]);
            $this->audit->log('org_unit.created', 'org_unit', $unit->id, null, $unit->only(['uuid', 'parent_id', 'name', 'type']));

            return $unit;
        });

        return response()->json(['message' => 'เพิ่มหน่วยงานเรียบร้อยแล้ว', 'uuid' => $unit->uuid], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $unit = OrgUnit::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_uuid' => ['sometimes', 'required', 'string', 'exists:org_units,uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        $before = $unit->only(['parent_id', 'name', 'is_active']);
        $linked = DB::table('department_org_unit_map')->where('org_unit_id', $unit->id)->exists();

        if (array_key_exists('name', $data) && trim($data['name']) !== $unit->name && $linked) {
            throw ValidationException::withMessages(['name' => ['หน่วยงานนี้ผูกกับแผนก กรุณาแก้ชื่อที่หน้าจัดการแผนก']]);
        }

        if (array_key_exists('parent_uuid', $data)) {
            abort_if($unit->parent_id === null, 422, 'ย้ายหน่วยงานระดับสถานศึกษาไม่ได้');
            $parent = OrgUnit::where('uuid', $data['parent_uuid'])->firstOrFail();
            // ห้ามย้ายไปอยู่ใต้ตัวเองหรือหน่วยย่อยของตัวเอง — ผังจะวนและ scope แบบ DESCENDANTS จะไม่จบ
            if (in_array($parent->id, $this->subtreeIds($unit->id), true)) {
                throw ValidationException::withMessages(['parent_uuid' => ['ย้ายหน่วยงานไปอยู่ใต้ตัวเองหรือหน่วยย่อยของตัวเองไม่ได้']]);
            }
            abort_unless($parent->is_active, 422, 'หน่วยงานแม่ถูกปิดใช้งานแล้ว');
            $unit->parent_id = $parent->id;
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $unit->is_active) {
            $this->assertCanDeactivate($unit);
        }

        if (array_key_exists('name', $data)) {
            $unit->name = trim($data['name']);
        }
        if (array_key_exists('is_active', $data)) {
            abort_if($unit->parent_id === null && ! $data['is_active'], 422, 'ปิดใช้งานหน่วยงานระดับสถานศึกษาไม่ได้');
            $unit->is_active = (bool) $data['is_active'];
        }

        DB::transaction(function () use ($unit, $before) {
            $unit->save();
            $this->audit->log('org_unit.updated', 'org_unit', $unit->id, $before, $unit->only(['parent_id', 'name', 'is_active']));
        });

        return response()->json(['message' => 'บันทึกหน่วยงานเรียบร้อยแล้ว']);
    }

    public function storeManager(Request $request, string $uuid): JsonResponse
    {
        $unit = OrgUnit::where('uuid', $uuid)->firstOrFail();
        abort_unless($unit->is_active, 422, 'หน่วยงานนี้ถูกปิดใช้งานแล้ว');

        $data = $request->validate([
            'user_uuid' => ['required', 'string', 'exists:users,uuid'],
            'assignment_type' => ['required', Rule::in(OrgUnitManager::TYPES)],
            'scope' => ['required', Rule::in(OrgUnitManager::SCOPES)],
            'order_number' => ['required', 'string', 'max:100'],
            'order_date' => ['required', 'date'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'note' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $user = User::where('uuid', $data['user_uuid'])->firstOrFail();
        abort_unless($user->is_active, 422, 'ผู้ใช้นี้ถูกระงับการใช้งาน ตั้งเป็นหัวหน้าหน่วยงานไม่ได้');

        // ห้ามมีการมอบหมายชนิดเดียวกันของคนเดิมในหน่วยเดิมซ้อนช่วงเวลากัน
        $overlap = OrgUnitManager::query()
            ->where('org_unit_id', $unit->id)
            ->where('user_id', $user->id)
            ->where('assignment_type', $data['assignment_type'])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->when($data['effective_to'] ?? null, fn ($q, $to) => $q->whereDate('effective_from', '<=', $to))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['user_uuid' => ['ผู้ใช้นี้มีการมอบหมายชนิดเดียวกันในหน่วยงานนี้ซ้อนช่วงเวลาอยู่แล้ว']]);
        }

        $manager = DB::transaction(function () use ($data, $unit, $user, $request) {
            $manager = OrgUnitManager::create([
                'org_unit_id' => $unit->id,
                'user_id' => $user->id,
                'assignment_type' => $data['assignment_type'],
                'scope' => $data['scope'],
                'order_number' => trim($data['order_number']),
                'order_date' => $data['order_date'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->log('org_unit.manager_assigned', 'org_unit_manager', $manager->id, null, $manager->only([
                'uuid', 'org_unit_id', 'user_id', 'assignment_type', 'scope', 'order_number', 'effective_from', 'effective_to',
            ]) + ['order_date' => $data['order_date']], reason: 'คำสั่งที่ '.trim($data['order_number']));

            return $manager;
        });

        return response()->json(['message' => 'บันทึกหัวหน้าหน่วยงานเรียบร้อยแล้ว', 'uuid' => $manager->uuid], 201);
    }

    /**
     * ปิดวาระ — ไม่ลบแถว เพราะใบลาที่อนุมัติไปแล้วยังอ้างถึงผู้อนุมัติ ณ วันนั้น
     */
    public function endManager(Request $request, string $uuid): JsonResponse
    {
        $manager = OrgUnitManager::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $end = Carbon::parse($data['effective_to'])->toDateString();
        if ($end < $manager->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_to' => ['วันสิ้นสุดต้องไม่ก่อนวันเริ่มมีผล']]);
        }
        if ($manager->effective_to !== null && $manager->effective_to->toDateString() < $end) {
            throw ValidationException::withMessages(['effective_to' => ['วาระนี้สิ้นสุดก่อนวันที่ระบุแล้ว']]);
        }

        $before = ['effective_to' => $manager->effective_to?->toDateString()];
        DB::transaction(function () use ($manager, $end, $data, $request, $before) {
            $manager->forceFill([
                'effective_to' => $end,
                'ended_by' => $request->user()->id,
                'note' => filled($data['note'] ?? null)
                    ? mb_substr(($manager->note ? $manager->note.' · ' : '').trim($data['note']), 0, 255)
                    : $manager->note,
            ])->save();
            $this->audit->log('org_unit.manager_ended', 'org_unit_manager', $manager->id, $before, ['effective_to' => $end], reason: $data['note'] ?? null);
        });

        return response()->json(['message' => 'ปิดวาระหัวหน้าหน่วยงานเรียบร้อยแล้ว']);
    }

    /**
     * เจ้าหน้าที่ประจำงานตามคำสั่ง (ระเบียบฯ 2569 ข้อ 13 วรรคสอง) — ขยายขอบเขตข้อมูล ไม่ให้สิทธิ์อนุมัติ
     */
    public function storeMember(Request $request, string $uuid): JsonResponse
    {
        $unit = OrgUnit::where('uuid', $uuid)->firstOrFail();
        abort_unless($unit->is_active, 422, 'หน่วยงานนี้ถูกปิดใช้งานแล้ว');

        $data = $request->validate([
            'user_uuid' => ['required', 'string', 'exists:users,uuid'],
            'order_number' => ['required', 'string', 'max:100'],
            'order_date' => ['required', 'date'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'note' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $user = User::where('uuid', $data['user_uuid'])->firstOrFail();
        abort_unless($user->is_active, 422, 'ผู้ใช้นี้ถูกระงับการใช้งาน');

        $overlap = OrgUnitMember::query()
            ->where('org_unit_id', $unit->id)->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->when($data['effective_to'] ?? null, fn ($q, $to) => $q->whereDate('effective_from', '<=', $to))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['user_uuid' => ['ผู้ใช้นี้ประจำหน่วยงานนี้ซ้อนช่วงเวลาอยู่แล้ว']]);
        }

        $member = DB::transaction(function () use ($data, $unit, $user, $request) {
            $member = OrgUnitMember::create([
                'org_unit_id' => $unit->id,
                'user_id' => $user->id,
                'order_number' => trim($data['order_number']),
                'order_date' => $data['order_date'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->log('org_unit.member_assigned', 'org_unit_member', $member->id, null, $member->only([
                'uuid', 'org_unit_id', 'user_id', 'order_number', 'effective_from', 'effective_to',
            ]), reason: 'คำสั่งที่ '.trim($data['order_number']));

            return $member;
        });

        return response()->json(['message' => 'บันทึกเจ้าหน้าที่ประจำงานเรียบร้อยแล้ว', 'uuid' => $member->uuid], 201);
    }

    public function endMember(Request $request, string $uuid): JsonResponse
    {
        $member = OrgUnitMember::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $end = Carbon::parse($data['effective_to'])->toDateString();
        if ($end < $member->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_to' => ['วันสิ้นสุดต้องไม่ก่อนวันเริ่มมีผล']]);
        }
        if ($member->effective_to !== null && $member->effective_to->toDateString() < $end) {
            throw ValidationException::withMessages(['effective_to' => ['การประจำงานนี้สิ้นสุดก่อนวันที่ระบุแล้ว']]);
        }

        $before = ['effective_to' => $member->effective_to?->toDateString()];
        DB::transaction(function () use ($member, $end, $data, $request, $before) {
            $member->forceFill(['effective_to' => $end, 'ended_by' => $request->user()->id])->save();
            $this->audit->log('org_unit.member_ended', 'org_unit_member', $member->id, $before, ['effective_to' => $end], reason: $data['note'] ?? null);
        });

        return response()->json(['message' => 'สิ้นสุดการประจำงานเรียบร้อยแล้ว']);
    }

    /**
     * ปิดหน่วยงานได้เมื่อไม่มีหน่วยย่อยที่ยังใช้งาน ไม่มีบุคลากรสังกัด ไม่มีหัวหน้าที่ยังมีผล
     * และไม่ถูกอ้างในสายอนุมัติ — มิฉะนั้นใบลาจะหาผู้อนุมัติไม่ได้หรือคนจะหลุดจากผัง
     */
    private function assertCanDeactivate(OrgUnit $unit): void
    {
        abort_if($unit->parent_id === null, 422, 'ปิดใช้งานหน่วยงานระดับสถานศึกษาไม่ได้');

        $reasons = [];
        if (OrgUnit::where('parent_id', $unit->id)->where('is_active', true)->exists()) {
            $reasons[] = 'ยังมีหน่วยงานย่อยที่ใช้งานอยู่';
        }
        if (User::where('org_unit_id', $unit->id)->where('is_active', true)->exists()) {
            $reasons[] = 'ยังมีบุคลากรสังกัดหน่วยงานนี้';
        }
        if (OrgUnitManager::where('org_unit_id', $unit->id)->inEffect()->exists()) {
            $reasons[] = 'ยังมีหัวหน้าหน่วยงานที่วาระยังไม่สิ้นสุด';
        }
        if (OrgUnitMember::where('org_unit_id', $unit->id)->inEffect()->exists()) {
            $reasons[] = 'ยังมีเจ้าหน้าที่ประจำงาน';
        }
        if (ApprovalStep::where('org_unit_id', $unit->id)->exists()) {
            $reasons[] = 'ถูกใช้ในลำดับการอนุมัติ';
        }

        if ($reasons !== []) {
            throw ValidationException::withMessages(['is_active' => ['ปิดใช้งานไม่ได้: '.implode(' · ', $reasons)]]);
        }
    }

    /** @return array<int,int> */
    private function subtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $frontier = OrgUnit::whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function managerPayload(OrgUnitManager $m, string $today): array
    {
        return [
            'uuid' => $m->uuid,
            'user' => $m->user ? [
                'uuid' => $m->user->uuid,
                'name' => $m->user->full_name_with_title ?? $m->user->name,
                'position' => $m->user->profile?->position,
                'is_active' => (bool) $m->user->is_active,
            ] : null,
            'assignment_type' => $m->assignment_type,
            'scope' => $m->scope,
            'order_number' => $m->order_number,
            'order_date' => $m->order_date?->toDateString(),
            'effective_from' => $m->effective_from?->toDateString(),
            'effective_to' => $m->effective_to?->toDateString(),
            'in_effect' => $m->effective_from?->toDateString() <= $today
                && ($m->effective_to === null || $m->effective_to->toDateString() >= $today),
            'note' => $m->note,
        ];
    }

    private function memberPayload(OrgUnitMember $m, string $today): array
    {
        return [
            'uuid' => $m->uuid,
            'user' => $m->user ? [
                'uuid' => $m->user->uuid,
                'name' => $m->user->full_name_with_title ?? $m->user->name,
                'is_active' => (bool) $m->user->is_active,
            ] : null,
            'order_number' => $m->order_number,
            'order_date' => $m->order_date?->toDateString(),
            'effective_from' => $m->effective_from?->toDateString(),
            'effective_to' => $m->effective_to?->toDateString(),
            'in_effect' => $m->effective_from?->toDateString() <= $today
                && ($m->effective_to === null || $m->effective_to->toDateString() >= $today),
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'กรุณากรอกชื่อหน่วยงาน',
            'type.in' => 'ชนิดหน่วยงานไม่ถูกต้อง',
            'parent_uuid.exists' => 'ไม่พบหน่วยงานแม่',
            'user_uuid.required' => 'กรุณาเลือกบุคลากร',
            'user_uuid.exists' => 'ไม่พบบุคลากรที่เลือก',
            'order_number.required' => 'กรุณากรอกเลขที่คำสั่ง',
            'order_date.required' => 'กรุณาระบุวันที่ของคำสั่ง',
            'effective_from.required' => 'กรุณาระบุวันเริ่มมีผล',
            'effective_to.after_or_equal' => 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มมีผล',
        ];
    }
}
