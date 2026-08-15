<?php

namespace App\Observers;

use App\Models\Department;
use App\Models\User;
use App\Services\PersonnelSyncService;

class UserObserver
{
    public function saving(User $user): void
    {
        if ($user->isDirty('department_id') && ! $user->isDirty('org_unit_id')) {
            $user->org_unit_id = $user->department_id
                ? Department::find($user->department_id)?->orgUnit?->id
                : null;
        } elseif ($user->isDirty('org_unit_id') && ! $user->isDirty('department_id')) {
            $user->department_id = app(PersonnelSyncService::class)->resolveDepartmentForOrgUnit($user->org_unit_id);
        }
    }

    public function saved(User $user): void
    {
        app(PersonnelSyncService::class)->syncFromUser($user);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('password') || ($user->wasChanged('is_active') && ! $user->is_active)) {
            $user->tokens()->delete();
        }
    }
}
