<?php

namespace App\Services\NotificationCenter;

use App\Models\NotificationCenter\NotificationEvent;
use App\Models\NotificationCenter\NotificationTemplate;

class TemplateRenderer
{
    public function render(NotificationTemplate $template, NotificationEvent $event): NotificationMessage
    {
        $values = collect($event->payload ?? [])->map(
            fn ($value) => is_scalar($value) ? (string) $value : ''
        );
        $replace = fn (array $matches): string => $values->get($matches[1], '');

        return new NotificationMessage(
            preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $replace, (string) ($template->subject ?? '')),
            preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $replace, $template->body_template),
            $event->related_type,
            $event->related_id,
        );
    }
}
