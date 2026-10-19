<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Support\Labels\LeaveActionLabel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['leave_id', 'user_id', 'action', 'details'])]
class LeaveActionLog extends Model
{
    protected $casts = [
        'details' => 'array',
    ];

    /** ป้ายภาษาไทยสำหรับแสดงผล — `action` เป็นรหัสภายใน ห้ามแสดงตรง ๆ */
    protected $appends = ['action_label'];

    public function getActionLabelAttribute(): string
    {
        return LeaveActionLabel::label($this->action);
    }

    protected static function booted(): void
    {
        static::created(fn (LeaveActionLog $log) => app(AuditLogger::class)->log('leave.action.'.$log->action, 'leave', $log->leave_id, null, $log->details));
    }

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
