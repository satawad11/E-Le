<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'leave_type_id', 'leave_policy_id', 'fiscal_year_id', 'document_number', 'verification_hash', 'content_hash', 'start_date', 'end_date', 'leave_period', 'total_days', 'paid_days', 'unpaid_days', 'reserved_days', 'reason', 'contact_address', 'attachment', 'status', 'approval_level', 'substitute_id', 'substitute_status', 'substitute_reason', 'hr_verified_by', 'hr_verified_at', 'cancellation_status', 'cancellation_reason', 'cancellation_date', 'meta_data', 'signature_path', 'notified_supervisor_at', 'submitted_at', 'voided_by', 'voided_at', 'void_reason'])]
class Leave extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'total_days' => 'float', 'paid_days' => 'float', 'unpaid_days' => 'float', 'reserved_days' => 'float', 'meta_data' => 'array', 'notified_supervisor_at' => 'datetime', 'submitted_at' => 'datetime', 'voided_at' => 'datetime'];
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

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function approvals()
    {
        return $this->hasMany(LeaveApproval::class);
    }

    public function evidences()
    {
        return $this->hasMany(LeaveEvidence::class);
    }

    public function adjustments()
    {
        return $this->hasMany(LeaveAdjustment::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(LeaveAuditLog::class);
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
