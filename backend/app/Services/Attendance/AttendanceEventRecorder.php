<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawEvent;
use App\Models\AttendanceSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class AttendanceEventRecorder
{
    /** @return array{event: AttendanceRawEvent, created: bool} */
    public function record(
        AttendanceSource $source,
        string $eventType,
        CarbonImmutable $occurredAt,
        string $dedupKey,
        array $payload = [],
        ?User $user = null,
        ?int $deviceId = null,
        ?string $externalPersonId = null,
        ?string $eventUuid = null,
        ?string $timezone = null,
    ): array {
        try {
            $event = AttendanceRawEvent::create([
                'event_uuid' => $eventUuid ?? (string) Str::uuid(),
                'attendance_source_id' => $source->getKey(),
                'device_id' => $deviceId,
                'user_id' => $user?->getKey(),
                'external_person_id' => $externalPersonId,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt->utc(),
                'timezone' => $timezone ?? $source->timezone ?? 'Asia/Bangkok',
                'dedup_key' => $dedupKey,
                'payload' => $payload === [] ? null : $payload,
            ]);

            return ['event' => $event, 'created' => true];
        } catch (QueryException $exception) {
            $existing = AttendanceRawEvent::query()
                ->where('event_uuid', $eventUuid)
                ->orWhere(fn ($query) => $query
                    ->where('attendance_source_id', $source->getKey())
                    ->where('dedup_key', $dedupKey))
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return ['event' => $existing, 'created' => false];
        }
    }
}
