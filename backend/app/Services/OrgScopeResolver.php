<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\User;

/**
 * ขอบเขตหน่วยงานของผู้ใช้ — รุ่นเปลี่ยนผ่าน REBUILD-2569 (R02-11)
 *
 * รุ่นเดิมตัดสินจาก `permission_scopes`, `org_unit_managers`, `org_unit_members` ซึ่งถูกตัดใน R-02
 * ระหว่างรอ PermissionEngine (R-04) คลาสนี้ **fail-closed**: ไม่มีผู้ใดได้ขอบเขตเกินตนเอง
 * และไม่มีการคืน "ไม่จำกัด" (null) อีกต่อไป
 *
 * R-04: แทนการเรียกทุกจุดด้วย `PermissionEngine::scopeQuery()` แล้วลบคลาสนี้
 * ค่าคงที่ของขอบเขตคงไว้ให้โค้ดโมดูลเดิมคอมไพล์ได้จนกว่าจะย้ายใน R-08
 */
class OrgScopeResolver
{
    public const SELF = 'SELF';

    public const UNIT = 'UNIT';

    public const DESCENDANTS = 'DESCENDANTS';

    public const ORGANIZATION = 'ORGANIZATION';

    public const ASSIGNED = 'ASSIGNED';

    public const CUSTOM = 'CUSTOM';

    /**
     * @return array{scope: string, org_unit_id: ?int}
     */
    public function resolve(User $user, string $ability, string $default = self::UNIT): array
    {
        return ['scope' => self::SELF, 'org_unit_id' => null];
    }

    /**
     * หน่วยงานที่มองเห็นได้ภายใต้สิทธิ์นี้ — ว่างเสมอระหว่าง R-02–R-03 (deny by default)
     *
     * @return array<int,int>
     */
    public function visibleOrgUnitIds(User $user, string $ability, string $default = self::UNIT): array
    {
        return [];
    }

    /** @return array<int,int> */
    public function managedOrgUnitIds(User $user, ?string $on = null): array
    {
        return [];
    }

    /**
     * หน่วยงานต้นสังกัด (assignment `primary`) ที่มีผล ณ วันที่ระบุ
     *
     * @return array<int,int>
     */
    public function memberOrgUnitIds(User $user, ?string $on = null): array
    {
        if ($user->personnel_id === null) {
            return [];
        }

        return Assignment::query()
            ->where('personnel_id', $user->personnel_id)
            ->where('kind', Assignment::KIND_PRIMARY)
            ->effectiveOn($on ?? today()->toDateString())
            ->pluck('org_unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canSeeOrgUnit(User $user, string $ability, ?int $orgUnitId, string $default = self::UNIT): bool
    {
        return false;
    }

    public function homeOrgUnitId(User $user): ?int
    {
        return $this->memberOrgUnitIds($user)[0] ?? null;
    }

    /**
     * หน่วยงานและหน่วยลูกทั้งหมด จาก materialized path (CORE-01)
     *
     * @return array<int,int>
     */
    public function subtreeIds(int $rootId): array
    {
        $path = OrgUnit::whereKey($rootId)->value('path');

        if ($path === null) {
            return [];
        }

        return OrgUnit::query()
            ->where('path', 'like', $path.'%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
