<?php

namespace App\Services;

use App\Models\OrgUnit;
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
     * ลำดับการตัดสิน:
     *   1. แถวใน `permission_scopes` ของผู้ใช้คนนี้ (ผู้ดูแลกำหนดเอง) — ชนะเสมอ
     *   2. การมอบหมายเป็นหัวหน้าหน่วยงานที่มีผลอยู่ ณ วันนี้ (Decision 3)
     *   3. ค่าเริ่มต้นของจุดเรียก ซึ่งเลือกให้ตรงกับพฤติกรรมเดิม
     *
     * @return array<int,int>|null
     */
    public function visibleOrgUnitIds(User $user, string $ability, string $default = self::UNIT): ?array
    {
        $explicit = $this->evaluator->scopeFor($user, $ability);

        if ($explicit !== null) {
            return $this->expand($user, $explicit['scope'], $explicit['org_unit_id']);
        }

        /*
         * LEGACY-04 (ปิดงาน) · Decision 3
         *
         * หัวหน้าแผนก/หัวหน้างาน/หัวหน้าฝ่าย/ผู้รักษาการ ไม่ได้เป็น "ชื่อ role"
         * แต่เป็นการมอบหมายใน `org_unit_managers` ขอบเขตของคนเหล่านี้จึงมาจาก
         * หน่วยงานที่ตนรับผิดชอบ ไม่ใช่จากหน่วยงานต้นสังกัดของตนเอง
         *
         * ถ้ายังไม่มีการมอบหมาย จะไม่มีผลใด ๆ กับใครทั้งสิ้น
         */
        $managed = $this->managedOrgUnitIds($user);

        if ($managed !== []) {
            return $managed;
        }

        return $this->expand($user, $default, null);
    }

    /**
     * หน่วยงานทั้งหมดที่ผู้ใช้รับผิดชอบในฐานะหัวหน้า/ผู้รักษาการ/ผู้รับมอบอำนาจ
     *
     * นับเฉพาะการมอบหมายที่ **มีผลอยู่ ณ วันที่ระบุ** เพื่อไม่ให้ผู้ที่พ้นหน้าที่
     * แล้วยังเห็นข้อมูลของหน่วยงานเดิม
     *
     * @return array<int,int>
     */
    public function managedOrgUnitIds(User $user, ?string $on = null): array
    {
        if (! DB::getSchemaBuilder()->hasTable('org_unit_managers')) {
            return [];
        }

        $date = $on ?? now()->toDateString();

        $assignments = DB::table('org_unit_managers')
            ->where('user_id', $user->getKey())
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get(['org_unit_id', 'scope']);

        $units = [];

        foreach ($assignments as $assignment) {
            $ids = $assignment->scope === self::DESCENDANTS
                ? $this->subtreeIds((int) $assignment->org_unit_id)
                : [(int) $assignment->org_unit_id];

            foreach ($ids as $id) {
                $units[$id] = $id;
            }
        }

        return array_values($units);
    }

    /**
     * แปลงชื่อขอบเขตเป็นรายการหน่วยงาน
     *
     * @return array<int,int>|null
     */
    private function expand(User $user, string $scope, ?int $orgUnitId): ?array
    {
        $home = $this->homeOrgUnitId($user);

        return match ($scope) {
            self::ORGANIZATION => null,
            self::SELF, self::ASSIGNED => [],
            self::CUSTOM => $orgUnitId === null ? [] : [$orgUnitId],
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
     * ลำดับ: การมอบหมายที่มีผลอยู่ใน `personnel_assignments` ก่อน
     * แล้วจึงหน่วยงานที่บันทึกไว้บนบัญชีผู้ใช้
     */
    public function homeOrgUnitId(User $user): ?int
    {
        // 1. Prioritize active PersonnelAssignment from Personnel Domain
        if ($user->relationLoaded('currentAssignment') && $user->currentAssignment?->org_unit_id !== null) {
            return (int) $user->currentAssignment->org_unit_id;
        }

        $activeAssignmentOrgUnitId = DB::table('personnel_assignments')
            ->where('user_id', $user->getKey())
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('org_unit_id');

        if ($activeAssignmentOrgUnitId !== null) {
            return (int) $activeAssignmentOrgUnitId;
        }

        /*
         * 2. หน่วยงานที่บันทึกไว้บนบัญชีผู้ใช้
         *
         * เดิมมีขั้นที่ 3 เป็นทางถอยผ่าน `users.department_id` + `department_org_unit_map`
         * สำหรับข้อมูลที่ยังไม่ backfill · ผังองค์กรเหลือชุดเดียวแล้ว ทั้งคอลัมน์และ
         * ตารางสะพานถูกถอดออก ทางถอยจึงหมดหน้าที่ไปพร้อมกัน
         */
        return $user->org_unit_id === null ? null : (int) $user->org_unit_id;
    }

    /**
     * ชื่อหน่วยงานของผู้ใช้ตามผังจริง — หน่วยงานต้นสังกัดและหน่วยงานแม่เหนือขึ้นไป
     *
     * **ที่มา:** เอกสารและรายงานเคยอ่านชื่อหน่วยงานจาก `users.department_id` ซึ่งเป็น
     * โครงเก่าที่มีได้ชั้นเดียว ขณะที่การอนุมัติ การมองเห็นข้อมูล และการหาหัวหน้า
     * ใช้ `org_units` ที่เป็นผังหลายชั้น ผลคือ **ใบลาที่พิมพ์ออกมาบอกหน่วยงานหนึ่ง
     * แต่ระบบเดินสายอนุมัติตามอีกหน่วยงานหนึ่ง** เมื่อสองชุดไม่ตรงกัน
     *
     * ตอนนี้ทุกที่อ่านจากที่เดียวกันคือผังจริง และตกไปใช้ชื่อแผนกเดิมก็ต่อเมื่อ
     * ผู้ใช้รายนั้นยังไม่มีข้อมูลในผัง
     *
     * แบบฟอร์มราชการแยก "ฝ่าย/กลุ่ม" กับ "แผนก/งาน" เป็นคนละช่อง เดิมระบบเติมค่า
     * เดียวกันลงทั้งสองช่องเพราะโครงเก่าไม่มีลำดับชั้นให้แยก
     *
     * @return array{unit: string, parent: string}
     */
    public function homeOrgUnitNames(User $user): array
    {
        $unitId = $this->homeOrgUnitId($user);

        // ผู้ใช้ที่ยังไม่มีหน่วยงานในผัง — ปล่อยช่องว่างไว้ ดีกว่าเดาชื่อให้ผิด
        if ($unitId === null) {
            return ['unit' => '', 'parent' => ''];
        }

        $unit = OrgUnit::query()
            ->with('parent:id,name,parent_id,type')
            ->find($unitId, ['id', 'name', 'parent_id']);

        if ($unit === null) {
            return ['unit' => '', 'parent' => ''];
        }

        /*
         * หน่วยงานแม่ใช้เป็นชื่อ "ฝ่าย/กลุ่ม" ได้ก็ต่อเมื่อเป็นหน่วยงานจริง
         *
         * ยอดสุดของผังคือ ORG-ROOT ซึ่งแทนตัวสถานศึกษาเอง (ชื่อมาจาก `app.name`)
         * ไม่ใช่ฝ่ายใดฝ่ายหนึ่ง — ถ้าเอามาใส่ช่อง "ฝ่าย" ผู้ลาจะได้เอกสารที่เขียนว่า
         * สังกัดฝ่ายชื่อเดียวกับชื่อระบบ · หน่วยงานที่แขวนอยู่ใต้ ORG-ROOT ตรง ๆ
         * (ผังแบบชั้นเดียว ซึ่งเป็นค่าเริ่มต้นของระบบที่ยังไม่ได้จัดผัง) จึงใช้ชื่อตัวเอง
         */
        $parent = $unit->parent;
        $parentIsOrganisation = $parent === null || $parent->parent_id === null && $parent->type === 'organization';

        return [
            'unit' => (string) $unit->name,
            'parent' => (string) ($parentIsOrganisation ? $unit->name : $parent->name),
        ];
    }

    /**
     * id ของหน่วยงานนี้ไล่ขึ้นไปจนถึงยอดผัง — ตัวเองอยู่หน้าสุด
     *
     * ใช้กับกติกาที่ "ประกาศที่หน่วยงานแม่แล้วมีผลกับลูกทั้งหมด" เช่นวันหยุดเฉพาะฝ่าย
     * ลำดับในอาร์เรย์คือลำดับความเฉพาะเจาะจง หน่วยที่อยู่ก่อนชนะหน่วยที่อยู่หลัง
     *
     * ไต่ทีละชั้นด้วยเหตุผลเดียวกับ `subtreeIds()` และกันวนซ้ำถ้าข้อมูลมี cycle
     *
     * @return array<int,int>
     */
    public function ancestorIds(int $unitId): array
    {
        $chain = [$unitId];
        $current = $unitId;

        while (true) {
            $parentId = DB::table('org_units')->where('id', $current)->value('parent_id');

            if ($parentId === null || in_array((int) $parentId, $chain, true)) {
                break;
            }

            $chain[] = (int) $parentId;
            $current = (int) $parentId;
        }

        return $chain;
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

    /**
     * หา id ของหน่วยงานระดับ "ฝ่ายหลัก" (Division) ที่หน่วยงานนี้สังกัดอยู่
     *
     * ในสถานศึกษาอาชีวศึกษา (สอศ.) คือ ๑ ใน ๔ ฝ่ายหลัก:
     * - ฝ่ายบริหารทรัพยากร
     * - ฝ่ายแผนงานและความร่วมมือ
     * - ฝ่ายพัฒนากิจการนักเรียน นักศึกษา
     * - ฝ่ายวิชาการ
     *
     * หน่วยงานระดับฝ่ายคือหน่วยงานที่มี parent_id ชี้ไปยัง ORG-ROOT (สถานศึกษา)
     */
    public function divisionOrgUnitId(int $unitId): ?int
    {
        $ancestors = $this->ancestorIds($unitId);

        // หา root unit (ยอดผังที่ไม่มี parent_id)
        $rootId = DB::table('org_units')->whereNull('parent_id')->value('id');

        if ($rootId === null) {
            return null;
        }

        /*
         * ในสายบรรพบุรุษ [$unitId, ..., $parent, $division, $rootId]
         * หน่วยงานระดับฝ่ายคือตัวที่อยู่ก่อนหน้า $rootId พอดี
         */
        $rootIndex = array_search((int) $rootId, $ancestors, true);

        if ($rootIndex !== false && $rootIndex > 0) {
            return (int) $ancestors[$rootIndex - 1];
        }

        // หากตัว $unitId เองเป็นฝ่าย หรือเป็น root
        return $unitId === (int) $rootId ? null : $unitId;
    }

    /**
     * ตรวจสอบว่าบุคลากรหรือหน่วยงานสองแห่งสังกัดอยู่ภายใต้ "ฝ่ายเดียวกัน" หรือไม่
     */
    public function isSameDivision(User|int $userOrUnitA, User|int $userOrUnitB): bool
    {
        $unitIdA = $userOrUnitA instanceof User ? $this->homeOrgUnitId($userOrUnitA) : (int) $userOrUnitA;
        $unitIdB = $userOrUnitB instanceof User ? $this->homeOrgUnitId($userOrUnitB) : (int) $userOrUnitB;

        if ($unitIdA === null || $unitIdB === null) {
            return false;
        }

        $divA = $this->divisionOrgUnitId($unitIdA);
        $divB = $this->divisionOrgUnitId($unitIdB);

        return $divA !== null && $divA === $divB;
    }
}
