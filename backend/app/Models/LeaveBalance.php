<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['user_id', 'leave_type_id', 'year', 'quota', 'carried_forward', 'used', 'reserved', 'leave_policy_id'])]
class LeaveBalance extends Model
{
    protected function casts(): array
    {
        return [
            'quota' => 'float',
            'used' => 'float',
            'reserved' => 'float',
            'carried_forward' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy()
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
