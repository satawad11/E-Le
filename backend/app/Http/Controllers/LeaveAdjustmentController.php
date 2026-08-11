<?php
namespace App\Http\Controllers;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveAuditLog;
use App\Models\LeaveBalance;
use App\Services\LeavePolicyService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class LeaveAdjustmentController extends Controller
{
    public function store(Request $r,Leave $leave,LeaveService $service){abort_unless($leave->user_id===$r->user()->id,403);abort_unless($leave->status==='Approved',422);$d=$r->validate(['cancel_from'=>'required|date|after_or_equal:'.$leave->start_date->toDateString(),'cancel_to'=>'required|date|after_or_equal:cancel_from|before_or_equal:'.$leave->end_date->toDateString(),'reason'=>'required|string|max:1000']);$days=$service->calculateTotalDays($d['cancel_from'],$d['cancel_to'],$leave->policy?->exclude_holidays??true);abort_if($days<=0||$days>=$leave->total_days,422,'กรณียกเลิกทั้งหมดให้ใช้คำขอยกเลิกใบลา');return response()->json(LeaveAdjustment::create(['leave_id'=>$leave->id,'requested_by'=>$r->user()->id,'cancel_from'=>$d['cancel_from'],'cancel_to'=>$d['cancel_to'],'days'=>$days,'reason'=>$d['reason']]),201);}
    public function decide(Request $r,LeaveAdjustment $adjustment,LeavePolicyService $policies){abort_unless($r->user()->can('leave.management.manage')||$r->user()->can('leave.approval.hr'),403);$d=$r->validate(['status'=>'required|in:Approved,Rejected']);abort_unless($adjustment->status==='Pending',422);DB::transaction(function()use($r,$adjustment,$d,$policies){$adjustment=LeaveAdjustment::whereKey($adjustment->id)->lockForUpdate()->first();if($d['status']==='Approved'){$leave=Leave::whereKey($adjustment->leave_id)->lockForUpdate()->first();$balance=LeaveBalance::where('user_id',$leave->user_id)->where('leave_type_id',$leave->leave_type_id)->where('year',$policies->periodYearForLeave($leave))->lockForUpdate()->first();if($balance){$balance->used=max(0,$balance->used-$adjustment->days);$balance->save();}$leave->total_days-=$adjustment->days;$leave->save();} $adjustment->update(['status'=>$d['status'],'approved_by'=>$r->user()->id,'decided_at'=>now()]);LeaveAuditLog::create(['leave_id'=>$adjustment->leave_id,'user_id'=>$r->user()->id,'action'=>'partial_cancellation_'.$d['status'],'after'=>$adjustment->toArray(),'ip_address'=>$r->ip(),'user_agent'=>$r->userAgent()]);});return $adjustment->fresh();}
}
