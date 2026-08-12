<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['leave_id', 'user_id', 'action', 'details'])]
class LeaveActionLog extends Model
{
    protected $casts = [
        'details' => 'array',
    ];

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
