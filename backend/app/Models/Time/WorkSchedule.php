<?php

namespace App\Models\Time;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'timezone', 'grace_minutes', 'early_leave_grace_minutes',
    'is_default', 'is_active', 'description',
])]
class WorkSchedule extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'grace_minutes' => 'integer',
            'early_leave_grace_minutes' => 'integer',
        ];
    }

    public function days()
    {
        return $this->hasMany(WorkScheduleDay::class)->orderBy('day_of_week');
    }

    public function assignments()
    {
        return $this->hasMany(WorkScheduleAssignment::class);
    }

    /**
     * เวลาทำงานของวันในสัปดาห์ที่ระบุ (0 = อาทิตย์ ... 6 = เสาร์)
     */
    public function dayOfWeek(int $dayOfWeek): ?WorkScheduleDay
    {
        return $this->days->firstWhere('day_of_week', $dayOfWeek);
    }
}
