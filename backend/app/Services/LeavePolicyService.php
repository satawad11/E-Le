<?php
namespace App\Services;
use App\Models\FiscalYear;use App\Models\Leave;use App\Models\LeaveBalance;use App\Models\LeavePolicy;use App\Models\LeaveType;use App\Models\PersonnelType;use App\Models\User;use Carbon\Carbon;use Illuminate\Support\Facades\DB;use Illuminate\Validation\ValidationException;
class LeavePolicyService
{
 public function resolve(User $user,int $leaveTypeId,Carbon $date):LeavePolicy
 {
  if(!$user->personnel_type_id){$user->personnel_type_id=PersonnelType::where('code','civil_servant')->value('id');$user->save();}
  $policy=LeavePolicy::where('personnel_type_id',$user->personnel_type_id)->where('leave_type_id',$leaveTypeId)->where('is_active',true)->whereDate('effective_from','<=',$date)->where(fn($q)=>$q->whereNull('effective_to')->orWhereDate('effective_to','>=',$date))->orderByDesc('effective_from')->first();
  if($policy)return $policy;
  $type=LeaveType::findOrFail($leaveTypeId);$legacyQuota=LeaveBalance::where('user_id',$user->id)->where('leave_type_id',$leaveTypeId)->value('quota');
  return LeavePolicy::create(['personnel_type_id'=>$user->personnel_type_id,'leave_type_id'=>$leaveTypeId,'effective_from'=>$date->copy()->startOfYear(),'annual_quota'=>$legacyQuota??$type->max_days,'notice_period_days'=>$type->notice_period_days??0,'allow_past_dates'=>$type->allow_past_dates??false,'exclude_holidays'=>$type->exclude_holidays??true,'allow_half_day'=>true,'attachment_required_after_days'=>$type->require_attachment_after_days,'required_evidence_type'=>($type->require_attachment_after_days!==null?'supporting_document':null),'is_carry_forward'=>$type->is_carry_forward??false,'max_carry_forward'=>$type->max_carry_forward??0,'period_basis'=>'fiscal_year','is_active'=>true]);
 }
 public function fiscalYear(Carbon $date):?FiscalYear{return FiscalYear::whereDate('start_date','<=',$date)->whereDate('end_date','>=',$date)->first();}
 /**
  * เลขปีงบประมาณที่ใช้เป็นคีย์ของ leave_balances.year — เก็บเป็น "ค.ศ. ของปีที่ปีงบประมาณสิ้นสุด" เสมอ
  * (ปีงบ พ.ศ.2569 = 1 ต.ค. 2025 – 30 ก.ย. 2026 → คีย์คือ 2026)
  * ตาราง fiscal_years เก็บ year เป็น พ.ศ. จึงต้องลบ 543 ก่อนใช้ ห้ามอ่าน ->year ตรง ๆ ที่อื่น
  */
 public function fiscalYearNumber(?FiscalYear $fy,Carbon $date):int{if($fy){$y=(int)$fy->year;return $y>2400?$y-543:$y;}return $date->month>=10?$date->year+1:$date->year;}
 public function periodYear(LeavePolicy $policy,Carbon $date):int{return $policy->period_basis==='calendar_year'?$date->year:$this->fiscalYearNumber($this->fiscalYear($date),$date);}
 /** ปีของ leave_balances ที่ใบลาใบหนึ่งต้องไปตัด — ใช้ร่วมกันทุกที่ที่ต้องหา balance ของใบลา */
 public function periodYearForLeave(Leave $leave):int{$start=Carbon::parse($leave->start_date);return $leave->policy?$this->periodYear($leave->policy,$start):$this->fiscalYearNumber($this->fiscalYear($start),$start);}
 public function assertEligible(User $user,LeavePolicy $policy,Carbon $start,Carbon $end,float $days,string $period,bool $hasEvidence):void
 {
  $serviceStart=optional($user->profile)->start_date?Carbon::parse($user->profile->start_date):null;if($serviceStart&&$serviceStart->diffInDays($start,false)<$policy->minimum_service_days)throw ValidationException::withMessages(['start_date'=>'อายุราชการ/อายุงานยังไม่ครบตามหลักเกณฑ์']);if(!$policy->allow_past_dates&&$start->lt(today()))throw ValidationException::withMessages(['start_date'=>'ไม่อนุญาตให้ยื่นลาย้อนหลัง']);if($policy->notice_period_days>0&&today()->diffInDays($start,false)<$policy->notice_period_days)throw ValidationException::withMessages(['start_date'=>"ต้องยื่นลาล่วงหน้าอย่างน้อย {$policy->notice_period_days} วัน"]);if($policy->max_days_per_request&&$days>$policy->max_days_per_request)throw ValidationException::withMessages(['end_date'=>'จำนวนวันลาเกินเพดานต่อครั้ง']);if($period!=='full_day'&&!$policy->allow_half_day)throw ValidationException::withMessages(['leave_period'=>'การลาประเภทนี้ไม่อนุญาตให้ลาครึ่งวัน']);if($policy->attachment_required_after_days!==null&&$days>=$policy->attachment_required_after_days&&!$hasEvidence)throw ValidationException::withMessages(['attachment'=>'ต้องแนบหลักฐานประกอบการลาตามหลักเกณฑ์']);if($start->year!==$end->year&&$policy->period_basis==='calendar_year')throw ValidationException::withMessages(['end_date'=>'กรุณาแยกใบลาที่ข้ามปีปฏิทิน']);
 }
 public function balance(User $user,LeavePolicy $policy,Carbon $start):LeaveBalance{return LeaveBalance::firstOrCreate(['user_id'=>$user->id,'leave_type_id'=>$policy->leave_type_id,'year'=>$this->periodYear($policy,$start)],['quota'=>$policy->annual_quota??0,'used'=>0,'reserved'=>0,'carried_forward'=>0,'leave_policy_id'=>$policy->id]);}
 /**
  * ปรับ quota ของ leave_balances ที่มีอยู่แล้วให้ตรงกับนโยบายที่เพิ่งบันทึก
  * (แถวเดิมถูกสร้างด้วย firstOrCreate จึงไม่อัปเดตตามนโยบายเอง)
  * แตะเฉพาะ quota/leave_policy_id — ไม่ยุ่งกับ used, reserved, carried_forward
  */
 public function syncBalances(LeavePolicy $policy):array
 {
  return DB::transaction(function()use($policy){
   $effective=Carbon::parse($policy->effective_from);
   $year=$this->periodYear($policy,$effective->isFuture()?$effective:today());
   $userIds=User::where('personnel_type_id',$policy->personnel_type_id)->pluck('id');
   if($userIds->isEmpty())return ['synced'=>0,'over_quota'=>0,'year'=>$year];
   $scope=fn()=>LeaveBalance::whereIn('user_id',$userIds)->where('leave_type_id',$policy->leave_type_id)->where('year',$year);
   $synced=$scope()->update(['quota'=>(float)($policy->annual_quota??0),'leave_policy_id'=>$policy->id,'updated_at'=>now()]);
   $overQuota=$scope()->whereRaw('used + reserved > quota + carried_forward')->count();
   return ['synced'=>$synced,'over_quota'=>$overQuota,'year'=>$year];
  });
 }
}
