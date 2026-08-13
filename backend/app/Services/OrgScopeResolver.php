<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * แปลง "ขอบเขตของสิทธิ์" เป็นชุดหน่วยงานที่ผู้ใช้มองเห็นได้ (LEGACY-04 · 04-3 · CORE-ORG-002)
 *
 * ใช้ต้นไม้ `org_units` ที่สร้างใน LEGACY-03 จึงรองรับหน่วยงานหลายระดับได้
 * ต่างจากเดิมที่เทียบ `department_id` ตรง ๆ ได้แค่ระดับเดียว
 *
 * ขอบเขตที่รองรับ:
 *   SELF          เห็นเฉพาะของตนเอง (ไม่คืนหน่วยงานใดเลย)
 *   UNIT          เห็นเฉพาะหน่วยงานของตน  ← ค่าเริ่มต้น เท่ากับพฤติกรรมเดิม
 *   DESCENDANTS   เห็นหน่วยงานของตนและหน่วยงานลูกทั้งหมด
 *   ORGANIZATION  เห็นทุกหน่วยงาน
 *   ASSIGNED      เห็นเฉพาะที่ได้รับมอบหมายเป็นรายกรณี (ผู้เรียกตัดสินเอง)
 *   CUSTOM        เห็นเฉพาะหน่วยงานที่ระบุไว้ใน permission_scopes.org_unit_id
 *
 * **สำคัญ:** ถ้าไม่มีแถวใน `permission_scopes` จะใช้ค่า `$default` ที่ผู้เรียกกำหนด
 * ซึ่งต้องเลือกให้ผลเท่ากับพฤติกรรมเดิมของจุดนั้น ๆ เสมอ
 *
 *   - การตรวจสิทธิ์รายรายการ (Policy)  ใช้ UNIT         — เดิมเทียบ department ตรง ๆ
 *   - การแสดงรายการ/รายงาน            ใช้ ORGANIZATION — เดิมไม่เคยถูกจำกัดเลย
 *     เพราะ role "หัวหน้าแผนกวิชา / หัวหน้าฝ่าย" ที่โค้ดเดิมใช้หรี่ขอบเขต
 *     **ไม่มีอยู่จริงในฐานข้อมูล** ตรรกะนั้นจึงไม่เคยทำงาน
 *
 * ผู้ดูแลจึงหรี่ขอบเขตให้รายคนได้ภายหลังด้วยการเพิ่มแถวใน `permission_scopes`
 * โดยไม่ต้องแก้โค้ด
 */
class OrgScopeResolver
{
    public const SELF = 'SELF';

    public const UNIT = 'UNIT';

    public const DESCENDANTS = 'DESCENDANTS';

    public const ORGANIZATION = 'ORGANIZATION';

    public const ASSIGNED = 'ASSIGNED';

    public const CUSTOM = 'CUSTOM';

    public function __construct(private readonly PermissionEvaluator $evaluator) {}

    /**
     * ขอบเขตที่มีผลกับผู้ใช้คนนี้สำหรับสิทธิ์นี้
     *
     * @return array{scope: string, org_unit_id: ?int}
     */
    public function resolve(User $user, string $ability, string $default = self::UNIT): array
    {
        return $this->evaluator->scopeFor($user, $ability)
            ?? ['scope' => $default, 'org_unit_id' => null];
    }

    /**
     * รายการ org_unit ที่ผู้ใช้มองเห็นได้ภายใต้สิทธิ์นี้
     *
     * คืน null แปลว่า "ไม่จำกัด" (ORGANIZATION) เพื่อให้ผู้เรียกข้ามการกรองไปได้เลย
     *
     * @return array<int,int>|null
     */
    public function visibleOrgUnitIds(User $user, string $ability, string $default = self::UNIT): ?array
    {
        $resolved = $this->resolve($user, $ability, $default);
        $home = $this->homeOrgUnitId($user);

        return match ($resolved['scope']) {
            self::ORGANIZATION => null,
            self::SELF, self::ASSIGNED => [],
            self::CUSTOM => $resolved['org_unit_id'] === null ? [] : [$resolved['org_unit_id']],
            self::DESCENDANTS => $home === null ? [] : $this->subtreeIds($home),
            default => $home === null ? [] : [$home],
        };
    }

    /**
     * ผู้ใช้คนนี้มองเห็นหน่วยงานนี้ได้หรือไม่
     */
    public function canSeeOrgUnit(User $user, string $ability, ?int $orgUnitId, string $default = self::UNIT): bool
    {
        $visible = $this->visibleOrgUnitIds($user, $ability, $default);

        if ($visible === null) {
            return true;
        }

        return $orgUnitId !== null && in_array($orgUnitId, $visible, true);
    }

    /**
     * หน่วยงานต้นสังกัดของผู้ใช้
     *
     * รองรับทั้ง `users.org_unit_id` (LEGACY-03) และ `users.department_id` เดิม
     * ที่ map ผ่าน `department_org_unit_map` เพื่อให้ข้อมูลที่ยังไม่ backfill ใช้งานได้
     */
    public function homeOrgUnitId(User $user): ?int
    {
        if ($user->org_unit_id !== null) {
            return (int) $user->org_unit_id;
        }

        $departmentId = $user->department_id ?? $user->profile?->department_id;

        if ($departmentId === null || ! DB::getSchemaBuilder()->hasTable('department_org_unit_map')) {
            return null;
        }

        $mapped = DB::table('department_org_unit_map')->where('department_id', $departmentId)->value('org_unit_id');

        return $mapped === null ? null : (int) $mapped;
    }

    /**
     * id ของหน่วยงานนี้และลูกหลานทั้งหมด
     *
     * ไต่ทีละชั้นแทน recursive CTE เพื่อให้ทำงานได้ทั้งบน MySQL 8 (production)
     * และ SQLite (ชุดทดสอบ) และกันวนซ้ำไม่รู้จบถ้าข้อมูลมี cycle
     *
     * @return array<int,int>
     */
    public function subtreeIds(int $rootId): array
    {
        $collected = [$rootId];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = DB::table('org_units')
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => in_array($id, $collected, true))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $collected = array_merge($collected, $children);
            $frontier = $children;
        }

        return $collected;
    }
}
