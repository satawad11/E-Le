<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = ['cancel_from' => 'date', 'cancel_to' => 'date', 'decided_at' => 'datetime', 'days' => 'float'];

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }
}
