<?php

namespace App\Models\Overtime;

use App\Models\Attendance;
use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'ot_assignment_id', 'user_id', 'attendance_id', 'actual_start', 'actual_end',
    'actual_minutes', 'certified_start', 'certified_end', 'certified_minutes',
    'status', 'source', 'notes', 'certified_by', 'certified_at',
    'reconciliation_status', 'reconciliation_details', 'reconciled_at', 'legacy_attendance_id',
])]
class OvertimeTimeRecord extends Model
{
    use HasPublicUuid;

    protected $table = 'ot_time_records';

    protected function casts(): array
    {
        return [
            'actual_start' => 'immutable_datetime', 'actual_end' => 'immutable_datetime',
            'certified_start' => 'immutable_datetime', 'certified_end' => 'immutable_datetime',
            'actual_minutes' => 'integer', 'certified_minutes' => 'integer',
            'certified_at' => 'datetime', 'reconciliation_details' => 'array', 'reconciled_at' => 'datetime',
        ];
    }

    public function assignment()
    {
        return $this->belongsTo(OvertimeAssignment::class, 'ot_assignment_id');
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
