<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['leave_id', 'stage', 'approver_id', 'approver_name', 'approver_position', 'signature_path', 'status', 'comment', 'approved_at', 'step_order', 'step_name', 'required_role_id', 'approver_type', 'specific_user_id', 'permission_name', 'allow_self_approval', 'delegated_from_user_id', 'is_self_approval', 'acted_at'])]
class LeaveApproval extends Model
{
    /** สายอนุมัติของการขอลา */
    public const STAGE_REQUEST = 'request';

    /** สายอนุมัติของคำขอยกเลิกใบลาที่อนุมัติไปแล้ว */
    public const STAGE_CANCELLATION = 'cancellation';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'acted_at' => 'datetime', 'allow_self_approval' => 'boolean', 'is_self_approval' => 'boolean'];
    }

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function requiredRole()
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }
}
