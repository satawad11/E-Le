<?php

namespace App\Services\NotificationCenter;

final readonly class TestDeliveryOutcome
{
    private function __construct(public bool $successful, public ?string $reason = null) {}

    public static function sent(): self
    {
        return new self(true);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
