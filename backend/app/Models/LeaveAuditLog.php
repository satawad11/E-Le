<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
}
