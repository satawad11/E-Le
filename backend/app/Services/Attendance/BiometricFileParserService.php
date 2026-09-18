<?php

namespace App\Services\Attendance;

use App\Models\AttendanceIdentityMap;
use App\Models\AttendanceSource;
use App\Models\User;
use Carbon\CarbonImmutable;

class BiometricFileParserService
{
    public function __construct(
        private readonly AttendanceEventRecorder $eventRecorder,
        private readonly AttendanceReconciliationService $reconciliationService,
    ) {}

    /**
     * Parse text/dat/csv content from biometric scan machines.
     *
     * @return array<int, array{enroll_id: string, timestamp: CarbonImmutable, event_type: string, raw: string}>
     */
    public function parseContent(string $content, string $timezone = 'Asia/Bangkok'): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $events = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with(strtolower($line), 'user id') || str_starts_with(strtolower($line), 'enroll id')) {
                continue;
            }

            $parsed = $this->parseLine($line, $timezone);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }

        return $events;
    }

    /**
     * Ingest parsed events into the Attendance system.
     *
     * @param  array<int, array{enroll_id: string, timestamp: CarbonImmutable, event_type: string, raw: string}>  $events
     * @return array{total_events: int, imported: int, duplicates: int, mapped_users: int, unmapped_enroll_ids: array<string>}
     */
    public function ingest(array $events, AttendanceSource $source, ?int $deviceId = null): array
    {
        $importedCount = 0;
        $duplicateCount = 0;
        $mappedUserIds = [];
        $unmappedEnrollIds = [];
        $affectedDatesByUser = [];

        // Preload identity maps for this source
        $identityMaps = AttendanceIdentityMap::query()
            ->where('attendance_source_id', $source->getKey())
            ->pluck('user_id', 'external_person_id')
            ->all();

        foreach ($events as $event) {
            $enrollId = (string) $event['enroll_id'];
            $occurredAt = $event['timestamp'];
            $eventType = $event['event_type'];

            $userId = $identityMaps[$enrollId] ?? null;

            // Fallback: Check if user exists by ID or employee code if no explicit identity map
            if ($userId === null) {
                $fallbackUser = User::query()->where('id', $enrollId)->orWhere('username', $enrollId)->first(['id']);
                if ($fallbackUser !== null) {
                    $userId = (int) $fallbackUser->id;
                }
            }

            if ($userId === null) {
                $unmappedEnrollIds[$enrollId] = $enrollId;
            } else {
                $mappedUserIds[$userId] = $userId;
                $dateKey = $occurredAt->setTimezone($source->timezone ?? 'Asia/Bangkok')->toDateString();
                $affectedDatesByUser[$userId][$dateKey] = true;
            }

            $dedupKey = "biometric:{$source->id}:{$enrollId}:{$occurredAt->toIso8601String()}";

            $recordResult = $this->eventRecorder->record(
                source: $source,
                eventType: $eventType,
                occurredAt: $occurredAt,
                dedupKey: $dedupKey,
                payload: ['raw_line' => $event['raw']],
                user: $userId !== null ? User::find($userId) : null,
                deviceId: $deviceId,
                externalPersonId: $enrollId,
                timezone: $source->timezone ?? 'Asia/Bangkok',
            );

            if ($recordResult['created']) {
                $importedCount++;
                if ($recordResult['event']->user_id !== null) {
                    $this->reconciliationService->reconcile($recordResult['event']);
                }
            } else {
                $duplicateCount++;
            }
        }

        return [
            'total_events' => count($events),
            'imported' => $importedCount,
            'duplicates' => $duplicateCount,
            'mapped_users' => count($mappedUserIds),
            'unmapped_enroll_ids' => array_values($unmappedEnrollIds),
        ];
    }

    /**
     * Parse a single line into biometric event data.
     *
     * @return array{enroll_id: string, timestamp: CarbonImmutable, event_type: string, raw: string}|null
     */
    private function parseLine(string $line, string $timezone = 'Asia/Bangkok'): array
    {
        // Delimiters: Tab (\t), Comma (,), or Semicolon (;)
        $tokens = preg_split('/[\t,;]+/', $line);
        if (count($tokens) < 2) {
            // Space delimited fallback (e.g. "1 2026-09-16 08:15:30 1")
            $tokens = preg_split('/\s+/', $line);
        }

        $tokens = array_map('trim', $tokens);
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        if (count($tokens) < 2) {
            return null;
        }

        $enrollId = $tokens[0];
        $timestamp = null;

        // Pattern 1: Token 1 is Date, Token 2 is Time (e.g., ["1", "2026-09-16", "08:15:30"])
        if (count($tokens) >= 3 && preg_match('/^\d{4}-\d{2}-\d{2}$|^\d{2}\/\d{2}\/\d{4}$/', $tokens[1]) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $tokens[2])) {
            try {
                $timestamp = CarbonImmutable::parse($tokens[1].' '.$tokens[2], $timezone);
            } catch (\Throwable) {
                $timestamp = null;
            }
        }

        // Pattern 2: Token 1 is full datetime string (e.g., ["1", "2026-09-16 08:15:30"])
        if ($timestamp === null) {
            try {
                $timestamp = CarbonImmutable::parse($tokens[1], $timezone);
            } catch (\Throwable) {
                $timestamp = null;
            }
        }

        if ($timestamp === null) {
            return null;
        }

        // Determine event type (check_in, check_out, or infer based on time)
        $eventType = null;
        if (count($tokens) >= 4) {
            $state = strtolower($tokens[3]);
            if (in_array($state, ['0', 'in', 'i', 'checkin', 'check_in'], true)) {
                $eventType = 'check_in';
            } elseif (in_array($state, ['1', 'out', 'o', 'checkout', 'check_out'], true)) {
                $eventType = 'check_out';
            }
        }

        if ($eventType === null) {
            $hour = (int) $timestamp->format('H');
            $eventType = $hour < 12 ? 'check_in' : 'check_out';
        }

        return [
            'enroll_id' => $enrollId,
            'timestamp' => $timestamp,
            'event_type' => $eventType,
            'raw' => $line,
        ];
    }
}
