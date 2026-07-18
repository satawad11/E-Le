<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'description', 'max_days', 'requires_doc', 'code_prefix', 'is_carry_forward', 'max_carry_forward', 'is_prorated'])]
class LeaveType extends Model
{
    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function balances()
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
