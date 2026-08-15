<?php

namespace App\Models\Overtime;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ot_order_id', 'user_id', 'assigned_by', 'status', 'assigned_at'])]
class OvertimeAssignment extends Model
{
    use HasPublicUuid;

    protected $table = 'ot_assignments';

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(OvertimeOrder::class, 'ot_order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function timeRecord()
    {
        return $this->hasOne(OvertimeTimeRecord::class, 'ot_assignment_id');
    }
}
