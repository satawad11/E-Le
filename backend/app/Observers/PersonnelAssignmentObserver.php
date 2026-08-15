<?php

namespace App\Observers;

use App\Models\PersonnelAssignment;
use App\Services\PersonnelSyncService;

class PersonnelAssignmentObserver
{
    public function saved(PersonnelAssignment $assignment): void
    {
        app(PersonnelSyncService::class)->syncFromAssignment($assignment);
    }
}
