<?php

namespace App\Jobs;

use App\Services\NotificationCenter\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(NotificationDeliveryService $service): void
    {
        $service->deliver($this->deliveryId);
    }
}
