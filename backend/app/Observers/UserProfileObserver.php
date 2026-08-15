<?php

namespace App\Observers;

use App\Models\UserProfile;
use App\Services\PersonnelSyncService;

class UserProfileObserver
{
    public function saved(UserProfile $profile): void
    {
        app(PersonnelSyncService::class)->syncFromProfile($profile);
    }
}
