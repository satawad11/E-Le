<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'attendance_id', 'user_id', 'requested_by', 'before_values', 'proposed_values',
    'reason', 'status', 'decided_by', 'decided_at', 'applied_at',
])]
class AttendanceCorrection extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'before_values' => 'array', 'proposed_values' => 'array',
            'decided_at' => 'datetime', 'applied_at' => 'datetime',
        ];
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
