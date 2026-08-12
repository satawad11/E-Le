<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role_id', 'step_order', 'name'])]
class ApprovalStep extends Model
{
    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
