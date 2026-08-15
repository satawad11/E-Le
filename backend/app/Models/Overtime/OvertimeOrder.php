<?php

namespace App\Models\Overtime;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'order_number', 'title', 'description', 'org_unit_id', 'timezone',
    'scheduled_start', 'scheduled_end', 'approved_start', 'approved_end',
    'status', 'created_by', 'submitted_at', 'approved_at',
])]
class OvertimeOrder extends Model
{
    use HasPublicUuid;

    protected $table = 'ot_orders';

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'immutable_datetime', 'scheduled_end' => 'immutable_datetime',
            'approved_start' => 'immutable_datetime', 'approved_end' => 'immutable_datetime',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function assignments()
    {
        return $this->hasMany(OvertimeAssignment::class, 'ot_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
