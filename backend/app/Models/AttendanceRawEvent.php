<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'event_uuid', 'attendance_source_id', 'device_id', 'user_id', 'external_person_id',
    'event_type', 'occurred_at', 'timezone', 'dedup_key', 'payload', 'attendance_id',
    'processed_at', 'processing_error',
])]
class AttendanceRawEvent extends Model
{
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime', 'payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            $mutable = ['attendance_id', 'processed_at', 'processing_error', 'updated_at'];
            if (array_diff(array_keys($event->getDirty()), $mutable) !== []) {
                throw new LogicException('ข้อมูลเหตุการณ์ลงเวลาดิบไม่สามารถแก้ไขได้');
            }
        });
        static::deleting(fn () => throw new LogicException('ข้อมูลเหตุการณ์ลงเวลาดิบไม่สามารถลบได้'));
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }
}
