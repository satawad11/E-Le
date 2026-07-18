<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['leave_id', 'approver_id', 'approver_name', 'approver_position', 'signature_path', 'status', 'comment', 'approved_at'])]
class LeaveApproval extends Model
{
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
