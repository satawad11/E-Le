<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['name','description','max_days','requires_doc','code_prefix','is_carry_forward','max_carry_forward','is_prorated','counts_toward_promotion'])]
class LeaveType extends Model
{
    protected function casts(): array { return ['counts_toward_promotion' => 'boolean']; }
    public function leaves(){return $this->hasMany(Leave::class);}
    public function balances(){return $this->hasMany(LeaveBalance::class);}
    public function policies(){return $this->hasMany(LeavePolicy::class);}
}
