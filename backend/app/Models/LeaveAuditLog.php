<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class LeaveAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::created(function (LeaveAuditLog $log): void {
            app(AuditLogger::class)->log(
                'leave.'.$log->action,
                'leave',
                $log->leave_id,
                $log->before,
                $log->after,
                null,
                $log->user_id
            );
        });
    }
}
