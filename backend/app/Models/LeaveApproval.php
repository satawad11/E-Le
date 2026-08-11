<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['leave_id','approver_id','approver_name','approver_position','signature_path','status','comment','approved_at','step_order','step_name','required_role_id','delegated_from_user_id','acted_at'])]
class LeaveApproval extends Model
{
    protected function casts():array{return ['approved_at'=>'datetime','acted_at'=>'datetime'];}
    public function leave(){return $this->belongsTo(Leave::class);}
    public function approver(){return $this->belongsTo(User::class,'approver_id');}
    public function requiredRole(){return $this->belongsTo(Role::class,'required_role_id');}
}
