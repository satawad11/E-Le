<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\LeaveApproval;
use App\Models\OrgUnit;
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

        $eligibleUserIds = match ($type) {
            ApprovalStep::RESOLVER_SPECIFIC_USER => $specificUserId ? [(int) $specificUserId] : [],
            ApprovalStep::RESOLVER_REQUESTER_SUPERVISOR, ApprovalStep::RESOLVER_DEPARTMENT_HEAD => $this->resolveDepartmentHeadIds($requester, $date, $roleId),
            ApprovalStep::RESOLVER_DIVISION_DIRECTOR => $this->resolveDivisionDirectorIds($requester, $date, $roleId),
            ApprovalStep::RESOLVER_DIRECTOR => $this->resolveDirectorIds($date, $roleId),
            ApprovalStep::RESOLVER_HR_OFFICER => $this->resolveHROfficerIds($roleId),
            default => $this->resolveByRoleIdOrPermission($roleId, $permissionName),
        };

        return array_values(array_unique(array_filter($eligibleUserIds)));
    }

    /**
     * ตรวจสอบว่า Actor มีสิทธิ์อนุมัติขั้นตอนนี้สำหรับ Requester หรือไม่ (รวม Delegation)
     */
    public function isAuthorizedApprover(User $actor, User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): bool
    {
        // 1. Super Admin ผ่านทุกข้อ
        if ($actor->can('system.manage') || $actor->roles->contains('name', 'Super Admin') || $actor->roles->contains('name', 'SuperAdmin')) {
            return true;
        }

        // 2. ห้ามอนุมัติใบลาของตนเอง
        if ($actor->id === $requester->id) {
            return false;
        }

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
     * หา User ID ของผู้มอบอำนาจในกรณีที่ทำหน้าที่แทน
     */
    public function getDelegatedFromId(User $actor, User $requester, ApprovalStep|LeaveApproval|array $step, ?string $on = null): ?int
    {
        $date = $on ?? now()->toDateString();
        $eligibleIds = $this->resolveEligibleApproverIds($requester, $step, $date);

        if (empty($eligibleIds) || ! Schema::hasTable('user_delegations')) {
            return null;
        }

        $delegation = UserDelegation::query()
            ->where('delegate_id', $actor->getKey())
            ->whereIn('user_id', $eligibleIds)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        return $delegation ? (int) $delegation->user_id : null;
    }

    private function resolveDepartmentHeadIds(User $requester, string $date, ?int $fallbackRoleId): array
    {
        $orgUnitId = $this->orgScope->homeOrgUnitId($requester);

        if ($orgUnitId !== null && Schema::hasTable('org_unit_managers')) {
            $managers = DB::table('org_unit_managers')
                ->where('org_unit_id', $orgUnitId)
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($managers)) {
                return $managers;
            }

            // Check parent unit if direct unit has no manager
            $parentUnitId = DB::table('org_units')->where('id', $orgUnitId)->value('parent_id');
            if ($parentUnitId) {
                $parentManagers = DB::table('org_unit_managers')
                    ->where('org_unit_id', $parentUnitId)
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (! empty($parentManagers)) {
                    return $parentManagers;
                }
            }
        }

        // Fallback to role or permissions
        return $this->resolveByRoleIdOrPermission($fallbackRoleId, 'leave.approval.dept', ['Head of Department', 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย']);
    }

    private function resolveDivisionDirectorIds(User $requester, string $date, ?int $fallbackRoleId): array
    {
        $orgUnitId = $this->orgScope->homeOrgUnitId($requester);

        if ($orgUnitId !== null && Schema::hasTable('org_unit_managers')) {
            $parentUnitId = DB::table('org_units')->where('id', $orgUnitId)->value('parent_id');
            if ($parentUnitId) {
                $managers = DB::table('org_unit_managers')
                    ->where('org_unit_id', $parentUnitId)
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (! empty($managers)) {
                    return $managers;
                }
            }
        }

        return $this->resolveByRoleIdOrPermission($fallbackRoleId, 'leave.approval.director', ['Deputy Director', 'รองผู้อำนวยการ']);
    }

    private function resolveDirectorIds(string $date, ?int $fallbackRoleId): array
    {
        if (Schema::hasTable('org_unit_managers')) {
            // Find root unit manager
            $rootUnitId = DB::table('org_units')->whereNull('parent_id')->value('id');
            if ($rootUnitId) {
                $managers = DB::table('org_unit_managers')
                    ->where('org_unit_id', $rootUnitId)
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (! empty($managers)) {
                    return $managers;
                }
            }
        }

        return $this->resolveByRoleIdOrPermission($fallbackRoleId, 'leave.approval.director', ['Director', 'ผู้อำนวยการ']);
    }

    private function resolveHROfficerIds(?int $fallbackRoleId): array
    {
        return $this->resolveByRoleIdOrPermission($fallbackRoleId, 'leave.management.manage', ['HR Head', 'HRHead', 'เจ้าหน้าที่บุคลากร (HR)']);
    }

    private function resolveByRoleIdOrPermission(?int $roleId, ?string $permissionName = null, array $roleNameFallbacks = []): array
    {
        $query = User::query()->where('is_active', true);

        $query->where(function ($sub) use ($roleId, $permissionName, $roleNameFallbacks) {
            $hasCondition = false;

            if ($roleId) {
                $sub->whereHas('roles', fn ($rq) => $rq->where('roles.id', $roleId));
                $hasCondition = true;
            }

            if (! empty($roleNameFallbacks)) {
                if ($hasCondition) {
                    $sub->orWhereHas('roles', fn ($rq) => $rq->whereIn('roles.name', $roleNameFallbacks));
                } else {
                    $sub->whereHas('roles', fn ($rq) => $rq->whereIn('roles.name', $roleNameFallbacks));
                    $hasCondition = true;
                }
            }

            if ($permissionName) {
                if ($hasCondition) {
                    $sub->orWhereHas('permissions', fn ($pq) => $pq->where('permissions.name', $permissionName));
                } else {
                    $sub->whereHas('permissions', fn ($pq) => $pq->where('permissions.name', $permissionName));
                }
            }
        });

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
