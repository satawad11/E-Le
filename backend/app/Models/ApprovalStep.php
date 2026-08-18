<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'role_id', 'step_order', 'name', 'approver_type',
    'specific_user_id', 'permission_name',
])]
class ApprovalStep extends Model
{
    public const RESOLVER_HR_OFFICER = 'HR_OFFICER';

    public const RESOLVER_REQUESTER_SUPERVISOR = 'REQUESTER_SUPERVISOR';

    public const RESOLVER_DEPARTMENT_HEAD = 'DEPARTMENT_HEAD';

    public const RESOLVER_DIVISION_DIRECTOR = 'DIVISION_DIRECTOR';

    public const RESOLVER_DIRECTOR = 'DIRECTOR';

    public const RESOLVER_ROLE = 'ROLE';

    public const RESOLVER_SPECIFIC_USER = 'SPECIFIC_USER';

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function specificUser()
    {
        return $this->belongsTo(User::class, 'specific_user_id');
    }
}
