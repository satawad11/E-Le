<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['user_id', 'leave_type_id', 'document_number', 'start_date', 'end_date', 'leave_period', 'total_days', 'reason', 'contact_address', 'attachment', 'status', 'approval_level', 'substitute_id', 'substitute_status', 'substitute_reason', 'hr_verified_by', 'hr_verified_at', 'cancellation_status', 'cancellation_reason', 'cancellation_date', 'meta_data', 'signature_path'])]
class Leave extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'float',
            'meta_data' => 'array',
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

    public function approvals()
    {
        return $this->hasMany(LeaveApproval::class);
    }

    public function substitute()
    {
        return $this->belongsTo(User::class, 'substitute_id');
    }

    public function hrVerifier()
    {
        return $this->belongsTo(User::class, 'hr_verified_by');
    }

    public function actionLogs()
    {
        return $this->hasMany(LeaveActionLog::class)->orderBy('created_at', 'desc');
    }
}
