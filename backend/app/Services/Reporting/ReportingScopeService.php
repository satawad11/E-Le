<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class ReportingScopeService
{
    public const REPORT_ABILITIES = [
        'personnel' => 'view_users',
        'leave' => 'leave.report.view',
        'leave_balance' => 'leave.report.view',
        'attendance' => 'attendance.view_all',
        'late' => 'attendance.view_all',
        'absence' => 'attendance.view_all',
        'ot' => 'overtime.management.review',
    ];

    public function __construct(private OrgScopeResolver $scopes) {}

    public function authorize(User $viewer, string $report): string
    {
        $ability = self::REPORT_ABILITIES[$report] ?? throw new \InvalidArgumentException("Unknown report: {$report}");
        if (! $viewer->can($ability)) {
            throw new AuthorizationException;
        }

        return $ability;
    }

    public function canView(User $viewer, string $report): bool
    {
        $ability = self::REPORT_ABILITIES[$report] ?? null;

        return $ability !== null && $viewer->can($ability);
    }

    public function users(User $viewer, string $ability, ReportFilter $filter, bool $activeOnly = false): Builder
    {
        $query = User::query()->select('users.id');
        $visible = $this->scopes->visibleOrgUnitIds($viewer, $ability, OrgScopeResolver::ORGANIZATION);

        if ($filter->orgUnitId !== null) {
            $requested = $this->scopes->subtreeIds($filter->orgUnitId);
            if ($visible !== null && ! in_array($filter->orgUnitId, $visible, true)) {
                throw new AuthorizationException;
            }
            $visible = $visible === null ? $requested : array_values(array_intersect($visible, $requested));
        }

        if ($visible !== null) {
            $query->whereIn('users.org_unit_id', $visible);
        }
        if ($filter->userId !== null) {
            $query->where('users.id', $filter->userId);
        }
        if ($filter->personnelTypeId !== null) {
            $query->where('users.personnel_type_id', $filter->personnelTypeId);
        }
        if ($activeOnly) {
            $query->where('users.is_active', true);
        }

        return $query;
    }

    public function visibleOrgUnitIds(User $viewer, string $ability, ReportFilter $filter): ?array
    {
        $visible = $this->scopes->visibleOrgUnitIds($viewer, $ability, OrgScopeResolver::ORGANIZATION);
        if ($filter->orgUnitId === null) {
            return $visible;
        }

        if ($visible !== null && ! in_array($filter->orgUnitId, $visible, true)) {
            throw new AuthorizationException;
        }

        $requested = $this->scopes->subtreeIds($filter->orgUnitId);

        return $visible === null ? $requested : array_values(array_intersect($visible, $requested));
    }
}
