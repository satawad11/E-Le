<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LeavePolicy extends Model
{
    protected $guarded = [];
    protected $casts = ['effective_from'=>'date','effective_to'=>'date','eligibility_rules'=>'array','allow_past_dates'=>'boolean','exclude_holidays'=>'boolean','allow_half_day'=>'boolean','requires_substitute'=>'boolean','is_carry_forward'=>'boolean','is_active'=>'boolean','annual_quota'=>'float','max_days_per_request'=>'float','max_days_with_pay'=>'float','attachment_required_after_days'=>'float','max_carry_forward'=>'float','max_accumulated'=>'float'];
    public function personnelType() { return $this->belongsTo(PersonnelType::class); }
    public function leaveType() { return $this->belongsTo(LeaveType::class); }
}
