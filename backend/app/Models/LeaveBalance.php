<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['user_id', 'leave_type_id', 'year', 'quota', 'carried_forward', 'used'])]
class LeaveBalance extends Model
{
    protected function casts(): array
    {
        return [
            'quota' => 'float',
            'used' => 'float',
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
}
