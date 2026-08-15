<?php

namespace App\Services\NotificationCenter;

final readonly class NotificationMessage
{
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $relatedType = null,
        public ?int $relatedId = null,
    ) {}
}
