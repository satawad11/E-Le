<?php

namespace App\Providers\Modules;

use App\Models\Attendance;
use App\Services\AuditLogger;
use Illuminate\Support\ServiceProvider;

class AttendanceModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Attendance::created(fn (Attendance $attendance) => app(AuditLogger::class)->log('attendance.created', 'attendance', $attendance->id, null, $attendance->getAttributes()));
        Attendance::updated(fn (Attendance $attendance) => app(AuditLogger::class)->log('attendance.updated', 'attendance', $attendance->id, $attendance->getOriginal(), $attendance->getAttributes()));
        Attendance::deleted(fn (Attendance $attendance) => app(AuditLogger::class)->log('attendance.deleted', 'attendance', $attendance->id, $attendance->getOriginal()));
    }
}
