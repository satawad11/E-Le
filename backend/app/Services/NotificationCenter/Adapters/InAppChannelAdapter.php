<?php

namespace App\Services\NotificationCenter\Adapters;

use App\Contracts\NotificationCenter\NotificationChannelAdapter;
use App\Models\User;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationMessage;

class InAppChannelAdapter implements NotificationChannelAdapter
{
    public function channel(): string
    {
        return 'in_app';
    }

    public function isConfiguredFor(User $recipient): bool
    {
        return true;
    }

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult
    {
        return DeliveryResult::sent();
    }
}
