<?php

namespace App\Services\NotificationCenter;

class NotificationPayloadSanitizer
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'secret', 'api_key',
        'national_id', 'id_card', 'citizen_id', 'access_token', 'refresh_token',
        'reason', 'medical_detail', 'attachment', 'content',
    ];

    public function sanitize(array $payload): array
    {
        return $this->sanitizeArray($payload, 0);
    }

    private function sanitizeArray(array $payload, int $depth): array
    {
        if ($depth > 3) {
            return [];
        }

        $clean = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->isSensitive($normalized)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeArray($value, $depth + 1);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr(strip_tags($value), 0, 500);
            }
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
