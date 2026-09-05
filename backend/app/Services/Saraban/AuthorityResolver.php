<?php

namespace App\Services\Saraban;

use App\Models\User;
use App\Services\OrgScopeResolver;
use App\Services\PermissionEvaluator;
use App\Services\TrustedClock;
use App\Services\Workflow\DelegationResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "ใครมีอำนาจอะไร กับหน่วยงานใด ณ เวลาใด" (SoT §13 · INT-08)
 *
 * **เป็น projection เท่านั้น — ไม่มีทะเบียนอำนาจของตัวเอง** ตามที่ SoT §13 บังคับ
 * ทุกคำตอบคำนวณสด ๆ จากแหล่งข้อมูลของระบบกลางทั้งสี่:
 *
 *   `permissions` (ผ่าน `PermissionEvaluator`)  ใครถือความสามารถนั้น
 *   `org_unit_managers`                        ใครเป็นหัวหน้า/ผู้รักษาการหน่วยงานใด ณ วันใด
 *   `permission_scopes` (ผ่าน `OrgScopeResolver`) ขอบเขตที่ผู้ดูแลกำหนดให้รายบุคคล
 *   `workflow_delegations` (ผ่าน `DelegationResolver`) ใครทำแทนใครอยู่
 *
 * ถ้าเก็บผลลัพธ์ไว้เป็นตารางของตัวเอง จะกลายเป็นข้อมูลคู่ขนานที่ล้าสมัยทันทีที่
 * มีการย้ายหน่วยงานหรือมอบอำนาจ แล้วตอบคำถามเรื่องอำนาจผิดโดยไม่มีใครรู้
 *
 * เหตุผลที่สารบรรณต้องมีตัวนี้ ทั้งที่ engine มี `permissionCandidates()` อยู่แล้ว:
 * ตัวของ engine คืน **ทุกคนในระบบที่ถือสิทธิ์นั้น** ซึ่งกว้างเกินไปสำหรับงานสารบรรณ —
 * เจ้าหน้าที่สารบรรณของฝ่าย ก. ไม่ควรกลายเป็นผู้มีสิทธิ์ตรวจหนังสือของฝ่าย ข.
 * เพียงเพราะถือสิทธิ์ชื่อเดียวกัน
 */
class AuthorityResolver
{
    public function __construct(
        private readonly PermissionEvaluator $evaluator,
        private readonly OrgScopeResolver $scopes,
        private readonly DelegationResolver $delegations,
        private readonly TrustedClock $clock,
    ) {}

    /**
     * ผู้มีอำนาจตามความสามารถนี้ กับหน่วยงานนี้ ณ วันนี้
     *
     * ลำดับการรวบรวม:
     *   1. ผู้ถือสิทธิ์ที่ **ขอบเขตครอบคลุมหน่วยงานนั้น**
     *   2. ผู้ที่ได้รับมอบอำนาจจากคนในข้อ 1 และการมอบยังมีผลอยู่
     *
     * @return array<int,int> user id
     */
    public function holdersFor(string $ability, ?int $orgUnitId, ?string $on = null): array
    {
        $date = $on ?? $this->clock->today();

        $holders = $this->usersHolding($ability)
            ->filter(fn (User $user) => $this->hasAuthorityOver($user, $ability, $orgUnitId, $date))
            ->values();

        $ids = $holders->map(fn (User $user) => (int) $user->getKey())->all();

        return array_values(array_unique(array_merge($ids, $this->delegatesOf($ids, $date))));
    }

    /**
     * ผู้ใช้คนนี้มีอำนาจตามความสามารถนี้ กับหน่วยงานนี้หรือไม่
     *
     * ใช้ตัดสินตอนสร้างหนังสือว่ายื่นแทนหน่วยงานนั้นได้ไหม
     */
    public function hasAuthorityOver(User $user, string $ability, ?int $orgUnitId, ?string $on = null): bool
    {
        if (! $this->evaluator->allows($user, $ability)) {
            return false;
        }

        if ($orgUnitId === null) {
            return true;
        }

        // เป็นหัวหน้า/ผู้รักษาการของหน่วยงานนั้นอยู่จริง ณ วันนั้น
        if ($this->managesUnit($user, $orgUnitId, $on ?? $this->clock->today())) {
            return true;
        }

        // หรืออยู่ในขอบเขตที่ผู้ดูแลกำหนดให้ (ค่าเริ่มต้น = หน่วยงานของตนเอง)
        return $this->scopes->canSeeOrgUnit($user, $ability, $orgUnitId, OrgScopeResolver::UNIT);
    }

    /**
     * หน่วยงานที่ผู้ใช้คนนี้ทำเรื่องแทนได้ภายใต้ความสามารถนี้
     *
     * คืน `null` = ไม่จำกัด (ระดับองค์กร) ตามสัญญาเดียวกับ `OrgScopeResolver`
     *
     * @return array<int,int>|null
     */
    public function unitsUnderAuthorityOf(User $user, string $ability, ?string $on = null): ?array
    {
        $scoped = $this->scopes->visibleOrgUnitIds($user, $ability, OrgScopeResolver::UNIT);

        if ($scoped === null) {
            return null;
        }

        $managed = $this->managedUnitIds($user, $on ?? $this->clock->today());

        return array_values(array_unique(array_merge($scoped, $managed)));
    }

    /**
     * ภาพรวมอำนาจของผู้ใช้คนหนึ่ง ณ วันหนึ่ง — ใช้ตอบคำถามของ SoT §13
     *
     * ตั้งใจคืนเป็นโครงสร้างที่อ่านแล้วเข้าใจได้ทันที เพราะปลายทางคือหน้าจอ
     * Authority Matrix (INT-16) และการตรวจสอบย้อนหลัง
     *
     * @param  array<int,string>  $abilities
     * @return array<string,mixed>
     */
    public function describeFor(User $user, array $abilities, ?string $on = null): array
    {
        $date = $on ?? $this->clock->today();

        return [
            'user_id' => (int) $user->getKey(),
            'as_of' => $date,
            'managed_org_units' => $this->managedUnitIds($user, $date),
            'acting_for' => array_column($this->delegations->activeDelegationsFor($user, $date), 'delegator_id'),
            'abilities' => collect($abilities)
                ->mapWithKeys(fn (string $ability) => [$ability => [
                    'granted' => $this->evaluator->allows($user, $ability),
                    'org_units' => $this->unitsUnderAuthorityOf($user, $ability, $date),
                ]])
                ->all(),
        ];
    }

    /**
     * ผู้ใช้ทุกคนที่ถือความสามารถนี้ (ผ่านตัวตัดสินกลาง จึงรู้จัก deny และชื่อที่เทียบเท่า)
     *
     * @return Collection<int,User>
     */
    private function usersHolding(string $ability)
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $this->evaluator->allows($user, $ability));
    }

    /** ผู้ที่รับมอบอำนาจจากคนในรายการนี้ และการมอบยังมีผล ณ วันนั้น */
    private function delegatesOf(array $delegatorIds, string $date): array
    {
        if ($delegatorIds === [] || ! $this->tableExists('workflow_delegations')) {
            return [];
        }

        return DB::table('workflow_delegations')
            ->whereIn('delegator_id', $delegatorIds)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->pluck('delegate_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function managesUnit(User $user, int $orgUnitId, string $date): bool
    {
        return in_array($orgUnitId, $this->managedUnitIds($user, $date), true);
    }

    /** @return array<int,int> */
    private function managedUnitIds(User $user, string $date): array
    {
        if (! $this->tableExists('org_unit_managers')) {
            return [];
        }

        return DB::table('org_unit_managers')
            ->where('user_id', $user->getKey())
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->pluck('org_unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}
