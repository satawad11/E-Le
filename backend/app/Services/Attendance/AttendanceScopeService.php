<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Database\Eloquent\Builder;

class AttendanceScopeService
{
    public function __construct(private readonly OrgScopeResolver $scopes) {}

    public function applyToAttendanceQuery(Builder $query, User $viewer): Builder
    {
        $visible = $this->scopes->visibleOrgUnitIds($viewer, 'attendance.view_all', OrgScopeResolver::ORGANIZATION);

        if ($visible !== null) {
            $query->whereHas('user', fn (Builder $users) => $users->whereIn('org_unit_id', $visible));
        }

        return $query;
    }

    public function applyToUserQuery(Builder $query, User $viewer): Builder
    {
        $visible = $this->scopes->visibleOrgUnitIds($viewer, 'attendance.view_all', OrgScopeResolver::ORGANIZATION);

        if ($visible !== null) {
            $query->whereIn('org_unit_id', $visible);
        }

        return $query;
    }

    public function applyToUserOwnedQuery(Builder $query, User $viewer, string $relation = 'user'): Builder
    {
        $visible = $this->scopes->visibleOrgUnitIds($viewer, 'attendance.view_all', OrgScopeResolver::ORGANIZATION);

        if ($visible !== null) {
            $query->whereHas($relation, fn (Builder $users) => $users->whereIn('org_unit_id', $visible));
        }

        return $query;
    }

    public function canSee(User $viewer, User $subject): bool
    {
        if ($viewer->is($subject)) {
            return true;
        }

        return $viewer->can('attendance.view_all')
            && $this->scopes->canSeeOrgUnit($viewer, 'attendance.view_all', $subject->org_unit_id, OrgScopeResolver::ORGANIZATION);
    }
}
