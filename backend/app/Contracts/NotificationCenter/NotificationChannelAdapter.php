<?php

namespace App\Contracts\NotificationCenter;

use App\Models\User;
use App\Services\NotificationCenter\DeliveryResult;
use App\Services\NotificationCenter\NotificationMessage;

interface NotificationChannelAdapter
{
    public function channel(): string;

    public function isConfiguredFor(User $recipient): bool;

    public function deliver(NotificationMessage $message, User $recipient): DeliveryResult;
}
