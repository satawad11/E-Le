<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ตรวจความพร้อมของสายอนุมัติ ก่อนที่ใบลาจะค้าง
 *
 * **ที่มา:** ตัวหาผู้อนุมัติเป็นแบบ fail-closed โดยเจตนา — หาไม่เจอ = ไม่มีใครมีสิทธิ์
 * ซึ่งถูกต้องในแง่ความปลอดภัย แต่ผลที่ผู้ใช้เห็นคือ "ใบลาค้างอยู่ที่ขั้นนี้เงียบ ๆ"
 * และไม่มีอะไรบอกผู้ดูแลว่าสาเหตุคือข้อมูลบุคลากรยังไม่ครบ ที่ผ่านมามีแต่หน้า
 * "ดูตัวอย่างผู้อนุมัติ" ซึ่งต้องเลือกผู้ยื่นทีละคน ผู้ดูแลจึงไม่มีทางรู้ล่วงหน้าว่า
 * ทั้งองค์กรมีใครบ้างที่จะติด
 *
 * คลาสนี้เดินตัวหาผู้อนุมัติ **ตัวเดียวกับที่ใช้ตัดสินสิทธิ์ตอนกดอนุมัติ** ให้ครบทุกคน
 * ที่ยังใช้งานอยู่ แล้วรายงานสามอย่าง:
 *
 *   1. ผู้ยื่นที่จะติด — ไม่มีผู้อนุมัติ หรือมีแต่ตัวเองซึ่งอนุมัติใบของตัวเองไม่ได้
 *   2. ขั้นตอนที่ตั้งค่าไว้แล้วใช้ไม่ได้จริง — ผู้ใช้เจาะจงที่ถูกปิดบัญชี บทบาทที่ไม่มีใครถือ
 *   3. ข้อมูลบุคลากรที่ยังไม่ครบ — ไม่มีสังกัด ไม่มีประเภทบุคลากร หน่วยงานที่ยังไม่มีหัวหน้า
 *
 * อ่านอย่างเดียว ไม่เปลี่ยนอะไรทั้งสิ้น
 */
class ApprovalChainReadiness
{
    /** จำนวนรายชื่อสูงสุดที่ส่งกลับต่อหนึ่งรายการ — ที่เหลือบอกเป็นจำนวน */
    private const SAMPLE_LIMIT = 20;

    /** ขั้นตอนที่หาตัวผู้อนุมัติจากผังองค์กร จึงต้องมีข้อมูลหัวหน้าหน่วยงานจริง */
    private const ORG_BASED = [
        ApprovalStep::RESOLVER_REQUESTER_SUPERVISOR,
        ApprovalStep::RESOLVER_DEPARTMENT_HEAD,
        ApprovalStep::RESOLVER_DIVISION_DIRECTOR,
        ApprovalStep::RESOLVER_DIRECTOR,
    ];

    public function __construct(
        private readonly ApproverResolverService $resolver,
        private readonly OrgScopeResolver $orgScope,
    ) {}

    /**
     * @param  Collection<int,array<string,mixed>>  $steps  ขั้นตอนในรูปแบบเดียวกับที่ preview ใช้
     * @return array<string,mixed>
     */
    public function inspect(Collection $steps): array
    {
        $users = User::query()->with('profile')->where('is_active', true)->get();

        /*
         * ผลของตัวหาผู้อนุมัติขึ้นกับผู้ยื่น "เฉพาะผ่านหน่วยงานต้นสังกัด" เท่านั้น
         * (ขั้นแบบบทบาท/สิทธิ์/ผู้ใช้เจาะจง ไม่ดูผู้ยื่นเลย) จึงคิดครั้งเดียวต่อหน่วยงาน
         * แทนที่จะคิดต่อคน — องค์กรพันคนก็ยังเป็นการคิวรีหลักสิบครั้ง ไม่ใช่หลักพัน
         */
        $homeUnitOf = [];
        $representative = [];

        foreach ($users as $user) {
            $unitId = $this->orgScope->homeOrgUnitId($user);
            $key = $unitId === null ? 'none' : (string) $unitId;
            $homeUnitOf[$user->id] = ['key' => $key, 'unit_id' => $unitId];
            $representative[$key] ??= $user;
        }

        $stepReports = [];
        $blockedByUser = [];

        foreach ($steps as $step) {
            $eligibleByUnit = [];

            foreach ($representative as $key => $sample) {
                $eligibleByUnit[$key] = $this->resolver->resolveEligibleApproverIds($sample, $step);
            }

            $noApprover = [];
            $selfOnly = [];

            foreach ($users as $user) {
                $eligible = $eligibleByUnit[$homeUnitOf[$user->id]['key']] ?? [];
                $usable = array_values(array_diff($eligible, [$user->id]));

                if (! empty($usable)) {
                    continue;
                }

                // ผู้ยื่นเป็นผู้อนุมัติของขั้นนี้เอง — ห้ามอนุมัติใบของตัวเอง ใบจึงค้าง
                $reason = in_array($user->id, $eligible, true) ? 'self_only' : 'no_approver';

                if ($reason === 'self_only') {
                    $selfOnly[] = $user;
                } else {
                    $noApprover[] = $user;
                }

                $blockedByUser[$user->id][] = [
                    'step_order' => $step['step_order'],
                    'step_name' => $step['name'],
                    'reason' => $reason,
                ];
            }

            $stepReports[] = [
                'step_order' => $step['step_order'],
                'name' => $step['name'],
                'approver_type' => $step['approver_type'],
                'config_problem' => $this->configProblem($step),
                'no_approver_count' => count($noApprover),
                'self_only_count' => count($selfOnly),
                'no_approver_sample' => $this->names($noApprover),
                'self_only_sample' => $this->names($selfOnly),
            ];
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'checked_users' => $users->count(),
            'blocked_users' => count($blockedByUser),
            'steps' => $stepReports,
            'blocked' => $this->blockedList($users, $blockedByUser),
            'data_gaps' => $this->dataGaps($users, $homeUnitOf, $steps),
        ];
    }

    /**
     * ขั้นตอนนี้ตั้งค่าไว้แล้วใช้ไม่ได้จริงหรือไม่ — ตรวจตัวการตั้งค่าเอง ไม่เกี่ยวกับผู้ยื่น
     *
     * @param  array<string,mixed>  $step
     */
    private function configProblem(array $step): ?string
    {
        $type = $step['approver_type'] ?? ApprovalStep::RESOLVER_ROLE;

        if ($type === ApprovalStep::RESOLVER_SPECIFIC_USER) {
            $target = $step['specific_user_id'] ? User::find($step['specific_user_id']) : null;

            return match (true) {
                $target === null => 'ขั้นนี้ระบุผู้อนุมัติเป็นบุคคลเจาะจง แต่ไม่พบผู้ใช้รายนั้นในระบบแล้ว',
                ! $target->is_active => 'ผู้อนุมัติที่ระบุไว้ ('.$target->name.') ถูกปิดการใช้งานบัญชีแล้ว',
                default => 'ขั้นนี้ผูกกับตัวบุคคล ไม่ใช่ตำแหน่ง — เมื่อผู้ใช้รายนี้ย้ายหรือเกษียณ สายอนุมัติจะหยุดทำงานทันที',
            };
        }

        if ($type === ApprovalStep::RESOLVER_ROLE) {
            if (empty($step['role_id'])) {
                return 'ขั้นแบบบทบาทแต่ยังไม่ได้เลือกบทบาท — ระบบจะถือว่าไม่มีใครมีสิทธิ์อนุมัติ';
            }

            $holders = User::query()->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $step['role_id']))
                ->count();

            if ($holders === 0) {
                $roleName = Role::find($step['role_id'])?->name ?? '#'.$step['role_id'];

                return 'ยังไม่มีผู้ใช้ที่ใช้งานอยู่ถือบทบาท "'.$roleName.'"';
            }
        }

        return null;
    }

    /**
     * ข้อมูลบุคลากรที่ยังไม่ครบจนกระทบการหาผู้อนุมัติ
     *
     * @param  Collection<int,User>  $users
     * @param  array<int,array{key:string,unit_id:int|null}>  $homeUnitOf
     * @param  Collection<int,array<string,mixed>>  $steps
     * @return array<string,mixed>
     */
    private function dataGaps(Collection $users, array $homeUnitOf, Collection $steps): array
    {
        $withoutOrgUnit = $users->filter(fn (User $u) => $homeUnitOf[$u->id]['unit_id'] === null)->values();
        $withoutPersonnelType = $users->filter(fn (User $u) => $u->personnel_type_id === null)->values();

        $usesOrgChart = $steps->contains(
            fn (array $step) => in_array($step['approver_type'] ?? '', self::ORG_BASED, true)
        );

        return [
            'without_org_unit' => [
                'count' => $withoutOrgUnit->count(),
                'sample' => $this->names($withoutOrgUnit->all()),
            ],
            'without_personnel_type' => [
                'count' => $withoutPersonnelType->count(),
                'sample' => $this->names($withoutPersonnelType->all()),
            ],
            /*
             * หน่วยงานที่ยังไม่มีหัวหน้าเป็นปัญหาก็ต่อเมื่อสายอนุมัติมีขั้นที่หาตัวจากผังองค์กร
             * ถ้าสายเป็นแบบบทบาททั้งหมด ข้อมูลนี้ไม่ได้ถูกใช้ จึงไม่ควรทักให้ผู้ดูแลเสียเวลา
             */
            'units_without_manager' => $usesOrgChart
                ? $this->unitsWithoutManager($users, $homeUnitOf)
                : ['count' => 0, 'sample' => [], 'not_applicable' => true],
        ];
    }

    /**
     * @param  Collection<int,User>  $users
     * @param  array<int,array{key:string,unit_id:int|null}>  $homeUnitOf
     * @return array<string,mixed>
     */
    private function unitsWithoutManager(Collection $users, array $homeUnitOf): array
    {
        if (! Schema::hasTable('org_unit_managers')) {
            return ['count' => 0, 'sample' => []];
        }

        $unitIds = collect($homeUnitOf)->pluck('unit_id')->filter()->unique()->values();

        if ($unitIds->isEmpty()) {
            return ['count' => 0, 'sample' => []];
        }

        $today = now()->toDateString();
        $managed = DB::table('org_unit_managers')
            ->whereIn('org_unit_id', $unitIds)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->pluck('org_unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $missing = $unitIds->reject(fn ($id) => $managed->contains((int) $id))->values();

        $names = DB::table('org_units')->whereIn('id', $missing)->pluck('name', 'id');
        $headcount = collect($homeUnitOf)->groupBy('unit_id')->map->count();

        return [
            'count' => $missing->count(),
            'sample' => $missing->take(self::SAMPLE_LIMIT)->map(fn ($id) => [
                'id' => (int) $id,
                'name' => $names[$id] ?? ('หน่วยงาน #'.$id),
                'users' => (int) ($headcount[$id] ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int,User>  $users
     * @param  array<int,array<int,array<string,mixed>>>  $blockedByUser
     * @return array<int,array<string,mixed>>
     */
    private function blockedList(Collection $users, array $blockedByUser): array
    {
        return $users
            ->filter(fn (User $user) => isset($blockedByUser[$user->id]))
            ->take(self::SAMPLE_LIMIT)
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->full_name_with_title ?? $user->name,
                'position' => $user->profile?->position,
                'problems' => $blockedByUser[$user->id],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,User>  $users
     * @return array<int,array<string,mixed>>
     */
    private function names(array $users): array
    {
        return collect($users)->take(self::SAMPLE_LIMIT)->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->full_name_with_title ?? $user->name,
        ])->values()->all();
    }
}
