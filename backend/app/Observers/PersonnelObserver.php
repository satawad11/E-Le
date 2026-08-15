<?php

namespace App\Observers;

use App\Models\Personnel;
use App\Services\PersonnelSyncService;

class PersonnelObserver
{
    public function saved(Personnel $personnel): void
    {
        app(PersonnelSyncService::class)->syncFromPersonnel($personnel);
    }
}
