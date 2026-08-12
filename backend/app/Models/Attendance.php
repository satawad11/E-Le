<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'user_id',
        'date',
        'check_in_time',
        'check_in_location',
        'check_out_time',
        'check_out_location',
        'distance_from_college',
        'status',
        'notes',
        'ot_check_in_time',
        'ot_check_out_time',
        'late_minutes',
        'early_leave_minutes',
        'ot_minutes',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'ot_check_in_time' => 'datetime',
        'ot_check_out_time' => 'datetime',
        'distance_from_college' => 'decimal:2',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'ot_minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
