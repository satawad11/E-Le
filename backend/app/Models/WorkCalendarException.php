<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkCalendarException extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'is_active' => 'boolean',
    ];

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function getIsWorkingDayAttribute(): bool
    {
        return $this->day_type === 'working_day';
    }
}
