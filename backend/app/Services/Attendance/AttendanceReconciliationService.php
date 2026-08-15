<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRawEvent;
use App\Models\User;
use App\Services\Time\TimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttendanceReconciliationService
{
    public function __construct(private readonly TimeService $time) {}

    public function reconcile(AttendanceRawEvent $event): Attendance
    {
        if ($event->attendance_id !== null && $event->processed_at !== null) {
            return $event->attendance()->firstOrFail();
        }

        return DB::transaction(function () use ($event) {
            $event = AttendanceRawEvent::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $user = $this->resolveUser($event);
            // Database timestamps are stored as UTC instants; parse the raw value explicitly
            // so the application timezone cannot reinterpret it as local wall-clock time.
            $moment = CarbonImmutable::parse((string) $event->getRawOriginal('occurred_at'), 'UTC')
                ->setTimezone($event->timezone ?: 'Asia/Bangkok');
            $workDate = $this->workDateFor($user, $moment, $event->event_type);

            $attendance = Attendance::query()
                ->where('user_id', $user->getKey())
                ->whereDate('date', $workDate->toDateString())
                ->lockForUpdate()
                ->first();

            $attendance ??= new Attendance(['user_id' => $user->getKey(), 'date' => $workDate->toDateString()]);
            $payload = $event->payload ?? [];

            if ($event->event_type === 'check_in') {
                if ($attendance->check_in_time === null || $moment->lt($attendance->check_in_time)) {
                    $attendance->check_in_time = $moment;
                    $attendance->check_in_location = $payload['location'] ?? $attendance->check_in_location;
                    $attendance->distance_from_college = $payload['distance'] ?? $attendance->distance_from_college;
                    $attendance->late_minutes = $this->time->lateMinutes($user, $moment, $workDate);
                    $attendance->status = $attendance->late_minutes > 0 ? 'Late' : 'Present';
                    $attendance->check_in_image = $payload['image_path'] ?? $attendance->check_in_image;
                    $attendance->notes = $this->mergeNotes($attendance->notes, $payload['notes'] ?? null);
                }
            } elseif ($event->event_type === 'check_out') {
                if ($attendance->check_out_time === null || $moment->gt($attendance->check_out_time)) {
                    $attendance->check_out_time = $moment;
                    $attendance->check_out_location = $payload['location'] ?? $attendance->check_out_location;
                    $attendance->early_leave_minutes = $this->time->earlyLeaveMinutes($user, $moment, $workDate);
                    $attendance->status ??= 'Absent';
                    $attendance->check_out_image = $payload['image_path'] ?? $attendance->check_out_image;
                    $attendance->notes = $this->mergeNotes($attendance->notes, $payload['notes'] ?? null);
                }
            } else {
                throw new RuntimeException("ชนิดเหตุการณ์ลงเวลา `{$event->event_type}` ไม่รองรับ");
            }

            $attendance->save();
            $event->update(['attendance_id' => $attendance->getKey(), 'processed_at' => now(), 'processing_error' => null]);

            return $attendance->refresh();
        }, 5);
    }

    private function resolveUser(AttendanceRawEvent $event): User
    {
        if ($event->user_id !== null) {
            return User::findOrFail($event->user_id);
        }

        $userId = DB::table('attendance_identity_maps')
            ->where('attendance_source_id', $event->attendance_source_id)
            ->where('external_person_id', $event->external_person_id)
            ->value('user_id');

        if ($userId === null) {
            throw new RuntimeException('ไม่พบการจับคู่ตัวตนของเหตุการณ์ลงเวลา');
        }

        return User::findOrFail($userId);
    }

    private function workDateFor(User $user, CarbonImmutable $moment, string $eventType): CarbonImmutable
    {
        $today = $moment->startOfDay();
        $previous = $today->subDay();
        $previousWindow = $this->time->shiftWindow($user, $previous);

        if ($previousWindow !== null && $previousWindow['crosses_midnight'] && $moment->lte($previousWindow['end'])) {
            if ($eventType === 'check_out' || $moment->gte($previousWindow['start'])) {
                return $previous;
            }
        }

        return $today;
    }

    private function mergeNotes(?string $existing, ?string $incoming): ?string
    {
        if (blank($incoming)) {
            return $existing;
        }

        return blank($existing) ? $incoming : $existing.' | '.$incoming;
    }
}
