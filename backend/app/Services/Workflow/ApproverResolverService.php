<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\OrgScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApproverResolverService
{
    public function __construct(
        private readonly OrgScopeResolver $orgScope,
        private readonly DelegationResolver $delegationResolver,
    ) {}

    /**
     * ค้นหา User IDs ทั้งหมดที่มีสิทธิ์อนุมัติในขั้นตอนนี้สำหรับผู้ยื่นคนนี้
     *
     * @return array<int,int>
     */
    public function resolveEligibleApproverIds(User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): array
    {
        $date = $on ?? now()->toDateString();
        $type = is_array($step) ? ($step['approver_type'] ?? ApprovalStep::RESOLVER_ROLE) : ($step->approver_type ?? ApprovalStep::RESOLVER_ROLE);
        $roleId = is_array($step) ? ($step['role_id'] ?? null) : ($step->role_id ?? $step->required_role_id ?? null);
        $specificUserId = is_array($step) ? ($step['specific_user_id'] ?? null) : ($step->specific_user_id ?? null);
        $permissionName = is_array($step) ? ($step['permission_name'] ?? null) : ($step->permission_name ?? null);
        $orgUnitId = is_array($step) ? ($step['org_unit_id'] ?? null) : ($step->org_unit_id ?? null);

        $eligibleUserIds = match ($type) {
            ApprovalStep::RESOLVER_SPECIFIC_USER => $specificUserId ? [(int) $specificUserId] : [],
            ApprovalStep::RESOLVER_REQUESTER_SUPERVISOR, ApprovalStep::RESOLVER_DEPARTMENT_HEAD => $this->resolveDepartmentHeadIds($requester, $date, $roleId),
            ApprovalStep::RESOLVER_DIVISION_DIRECTOR => $this->resolveDivisionDirectorIds($requester, $date, $roleId),
            ApprovalStep::RESOLVER_DIRECTOR => $this->resolveDirectorIds($date, $roleId),
            ApprovalStep::RESOLVER_HR_OFFICER => $this->resolveHROfficerIds($roleId),
            ApprovalStep::RESOLVER_ORG_UNIT_MANAGER => $this->resolveOrgUnitManagerIds($orgUnitId ? (int) $orgUnitId : null, $date, $roleId),
            default => $this->resolveByRoleIdOrPermission($roleId, $permissionName),
        };

        return array_values(array_unique(array_filter($eligibleUserIds)));
    }

    /**
     * ตรวจสอบว่า Actor มีสิทธิ์อนุมัติขั้นตอนนี้สำหรับ Requester หรือไม่ (รวม Delegation)
     */
    public function isAuthorizedApprover(User $actor, User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): bool
    {
        // 1. ใบลาของตนเอง — ผ่านได้ทางเดียวคือ canSelfApprove() สิทธิ์ผู้ดูแลระบบและการรับมอบอำนาจใช้ไม่ได้
        if ($actor->id === $requester->id) {
            return $this->canSelfApprove($requester, $step, $on);
        }

        // 2. (R02-11) ยกเลิกทางผ่านของ Super Admin — ผู้ดูแลระบบไม่อยู่ในสายอนุมัติ (PROJECT_RULES ข้อ 8)

        $date = $on ?? now()->toDateString();
        $eligibleIds = $this->resolveEligibleApproverIds($requester, $step, $date);

        // 3. ตรวจสอบสิทธิ์โดยตรง
        if (in_array($actor->id, $eligibleIds, true)) {
            return true;
        }

        // 4. ตรวจสอบสิทธิ์ผ่านการมอบหมายอำนาจ (Delegation)
        return $this->getDelegatedFromId($actor, $requester, $step, $date) !== null;
    }

    /**
     * ผู้ยื่นอนุมัติขั้นนี้ของใบลาตนเองได้หรือไม่ (LEAVE-APPROVAL-02)
     *
     * ต้องครบทั้งสองข้อ:
     *   - ขั้นนั้นเปิด allow_self_approval (ตั้งรายขั้นที่หน้าจัดการลำดับการอนุมัติ / pin ไว้กับใบลา)
     *   - ผู้ยื่นเป็นผู้อนุมัติของขั้นนั้นโดยตรง — ไม่นับสิทธิ์ผู้ดูแลระบบ `leave.management.manage`
     *     หรือการรับมอบอำนาจ เช่น รองผู้อำนวยการอนุมัติขั้นที่ 2 ของตนเองได้ แต่ขั้นผู้อำนวยการไม่ได้
     */
    public function canSelfApprove(User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): bool
    {
        $allowed = is_array($step) ? (bool) ($step['allow_self_approval'] ?? false) : (bool) $step->allow_self_approval;

        return $allowed && in_array($requester->id, $this->resolveEligibleApproverIds($requester, $step, $on), true);
    }

    /**
     * หา User ID ของผู้มอบอำนาจในกรณีที่ทำหน้าที่แทน
     */
    public function getDelegatedFromId(User $actor, User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): ?int
    {
        return $this->findDelegation($actor, $requester, $step, $on)?->user_id;
    }

    /**
     * รายการในทะเบียนการทำหน้าที่แทนที่ทำให้ actor ลงนามขั้นนี้ได้ — null ถ้าไม่ได้ทำหน้าที่แทน
     *
     * นับเฉพาะรายการที่รับรองแล้ว (approved) และมีผล ณ วันที่ — คำขอที่ยังรอรับรองใช้ไม่ได้
     */
    public function findDelegation(User $actor, User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): ?UserDelegation
    {
        // ใบลาของตนเองไม่อนุมัติผ่านการรับมอบอำนาจ — มิฉะนั้นผู้ที่ได้รับมอบจากใครก็ได้ในขั้นนั้น
        // จะอนุมัติขั้นที่ตนไม่มีสิทธิ์ให้ใบลาของตนเอง
        if ($actor->id === $requester->id) {
            return null;
        }

        $date = $on ?? now()->toDateString();
        // ผู้ยื่นมอบอำนาจให้คนอื่นมาอนุมัติใบลาของผู้ยื่นในนามผู้ยื่นไม่ได้ (LEAVE-APPROVAL-02)
        // ถ้าขั้นนั้นอนุญาตให้อนุมัติของตนเอง ผู้ยื่นต้องกดเองเพื่อให้บันทึกเป็น "อนุมัติด้วยตนเอง"
        $eligibleIds = array_values(array_diff($this->resolveEligibleApproverIds($requester, $step, $date), [$requester->id]));

        if (empty($eligibleIds) || ! Schema::hasTable('user_delegations')) {
            return null;
        }

        return UserDelegation::query()
            ->with('user.profile')
            ->where('delegate_id', $actor->getKey())
            ->whereIn('user_id', $eligibleIds)
            ->inEffect($date)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveDepartmentHeadIds(User $requester, string $date, ?int $fallbackRoleId): array
    {
        $orgUnitId = $this->orgScope->homeOrgUnitId($requester);

        if ($orgUnitId !== null && Schema::hasTable('org_unit_managers')) {
            $managers = $this->managerIdsOf($orgUnitId, $date);

            if (! empty($managers)) {
                return $managers;
            }

            // Check parent unit if direct unit has no manager
            $parentUnitId = DB::table('org_units')->where('id', $orgUnitId)->value('parent_id');
            if ($parentUnitId) {
                $parentManagers = $this->managerIdsOf((int) $parentUnitId, $date);

                if (! empty($parentManagers)) {
                    return $parentManagers;
                }
            }
        }

        return $this->fallback($fallbackRoleId, 'leave.approval.dept', ['Head of Department', 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย']);
    }

    private function resolveDivisionDirectorIds(User $requester, string $date, ?int $fallbackRoleId): array
    {
        $orgUnitId = $this->orgScope->homeOrgUnitId($requester);

        if ($orgUnitId !== null && Schema::hasTable('org_unit_managers')) {
            $parentUnitId = DB::table('org_units')->where('id', $orgUnitId)->value('parent_id');
            if ($parentUnitId) {
                $managers = $this->managerIdsOf((int) $parentUnitId, $date);

                if (! empty($managers)) {
                    return $managers;
                }
            }
        }

        return $this->fallback($fallbackRoleId, null, ['Deputy Director', 'รองผู้อำนวยการ']);
    }

    private function resolveDirectorIds(string $date, ?int $fallbackRoleId): array
    {
        if (Schema::hasTable('org_unit_managers')) {
            // Find root unit manager
            $rootUnitId = DB::table('org_units')->whereNull('parent_id')->value('id');
            if ($rootUnitId) {
                $managers = $this->managerIdsOf((int) $rootUnitId, $date);

                if (! empty($managers)) {
                    return $managers;
                }
            }
        }

        return $this->fallback($fallbackRoleId, null, ['Director', 'ผู้อำนวยการ']);
    }

    private function resolveHROfficerIds(?int $fallbackRoleId): array
    {
        // งานบุคลากรหาจากสิทธิ์ leave.management.manage เสมอ (ไม่ได้ผูกกับผังองค์กร)
        // ชื่อบทบาทเป็นเพียงทางถอยของระบบเดิม ปิดได้ด้วยสวิตช์เดียวกับขั้นอื่น
        return $this->resolveByRoleIdOrPermission(
            $fallbackRoleId,
            'leave.management.manage',
            $this->roleFallbackEnabled() ? ['HR Head', 'HRHead', 'เจ้าหน้าที่บุคลากร (HR)'] : [],
        );
    }

    /**
     * หัวหน้าของหน่วยงานที่ขั้นนี้ระบุไว้ (ORG_UNIT_MANAGER · ACCESS-GOV-01)
     *
     * ใช้กับขั้นที่ผู้อนุมัติไม่ได้ขึ้นกับต้นสังกัดของผู้ยื่น เช่น รองผู้อำนวยการฝ่ายบริหารทรัพยากร
     * ที่อนุมัติใบลาของทุกฝ่าย (คำตอบ Q3) — ไม่มีทางถอยตามชื่อบทบาทหรือ permission เลย
     * เพราะขั้นนี้ตั้งใจชี้ตัวคนตามคำสั่งมอบหมาย ทางถอยเดียวคือ role ที่ผู้ดูแลเลือกไว้ในขั้นเอง
     */
    private function resolveOrgUnitManagerIds(?int $orgUnitId, string $date, ?int $fallbackRoleId): array
    {
        if ($orgUnitId !== null && Schema::hasTable('org_unit_managers')) {
            $managers = $this->managerIdsOf($orgUnitId, $date);
            if (! empty($managers)) {
                return $managers;
            }
        }

        return $fallbackRoleId ? $this->resolveByRoleIdOrPermission($fallbackRoleId) : [];
    }

    /** ผู้ใช้ที่เป็นหัวหน้า/รักษาการ/รับมอบของหน่วยงาน ณ วันที่ (ตามวันมีผลของคำสั่ง) */
    private function managerIdsOf(int $orgUnitId, string $date): array
    {
        return DB::table('org_unit_managers')
            ->where('org_unit_id', $orgUnitId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ทางถอยเมื่อหาหัวหน้าจากผังองค์กรไม่พบ
     *
     * role ที่ผู้ดูแลเลือกไว้ในขั้น (role_id) ใช้ได้เสมอเพราะเป็นข้อมูลที่ตั้งใจตั้ง
     * ส่วน permission กลางและชื่อบทบาทมาตรฐานทำให้ "ทุกคนที่มีบทบาทนั้น" อนุมัติได้ทุกฝ่าย
     * จึงปิดได้ด้วย workflow.approver_role_fallback (production ควรปิดหลังกรอกหัวหน้าหน่วยงานครบ)
     */
    private function fallback(?int $fallbackRoleId, ?string $permissionName, array $roleNames): array
    {
        if (! $this->roleFallbackEnabled()) {
            return $fallbackRoleId ? $this->resolveByRoleIdOrPermission($fallbackRoleId) : [];
        }

        return $this->resolveByRoleIdOrPermission($fallbackRoleId, $permissionName, $roleNames);
    }

    private function roleFallbackEnabled(): bool
    {
        return (bool) config('workflow.approver_role_fallback', true);
    }

    private function resolveByRoleIdOrPermission(?int $roleId, ?string $permissionName = null, array $roleNameFallbacks = []): array
    {
        /*
         * R02-11 — ตาราง role/permission ของ spatie ถูกตัดแล้ว (ADR-R-003) จึงไม่มีผู้ใดผ่านเกณฑ์นี้
         * (fail-closed) จนกว่า R-04 จะแทน resolver นี้ด้วย `head_of_level` / `functional_template`
         * / `specific_assignment` ตาม CORE-05 ซึ่งไม่มี fallback ตามชื่อ role
         */
        return [];
    }
}
