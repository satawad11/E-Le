<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveAuditLog;
use App\Models\LeaveBalance;
use App\Services\LeavePolicyService;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ยกเลิกวันลาบางส่วน (ตัดบางวันออกจากใบลาที่อนุมัติแล้ว)
 */
class LeaveAdjustmentController extends Controller
{
    public function store(Request $r, Leave $leave, LeaveService $service)
    {
        abort_unless($leave->user_id === $r->user()->id, 403);
        abort_unless($leave->status === 'Approved', 422, 'ยกเลิกบางส่วนได้เฉพาะใบลาที่อนุมัติแล้ว');
        $d = $r->validate(['cancel_from' => 'required|date|after_or_equal:'.$leave->start_date->toDateString(), 'cancel_to' => 'required|date|after_or_equal:cancel_from|before_or_equal:'.$leave->end_date->toDateString(), 'reason' => 'required|string|max:1000']);

        // ใช้ตารางเวลาของเจ้าของใบลา ให้นับวันตรงกับตอนยื่นลา
        $days = $service->calculateTotalDays($d['cancel_from'], $d['cancel_to'], $leave->policy?->exclude_holidays ?? true, $leave->user?->department_id, $leave->user);
        abort_if($days <= 0, 422, 'ช่วงวันที่เลือกไม่มีวันทำการ');

        $existing = LeaveAdjustment::where('leave_id', $leave->id)->whereIn('status', ['Pending', 'Approved'])->get();
        $overlaps = $existing->contains(fn ($a) => $a->cancel_from->toDateString() <= $d['cancel_to'] && $a->cancel_to->toDateString() >= $d['cancel_from']);
        abort_if($overlaps, 422, 'ช่วงวันที่นี้มีคำขอยกเลิกบางส่วนอยู่แล้ว');
        abort_if($days >= $leave->total_days - (float) $existing->where('status', 'Pending')->sum('days'), 422, 'กรณียกเลิกทั้งหมดให้ใช้คำขอยกเลิกใบลา');

        $adjustment = LeaveAdjustment::create(['leave_id' => $leave->id, 'requested_by' => $r->user()->id, 'cancel_from' => $d['cancel_from'], 'cancel_to' => $d['cancel_to'], 'days' => $days, 'reason' => $d['reason']]);
        LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $r->user()->id, 'action' => 'partial_cancellation_requested', 'after' => $adjustment->toArray(), 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);

        return response()->json($adjustment, 201);
    }

    public function decide(Request $r, LeaveAdjustment $adjustment, LeavePolicyService $policies)
    {
        abort_unless($r->user()->can('leave.management.manage') || $r->user()->can('leave.approval.hr'), 403);
        $d = $r->validate(['status' => 'required|in:Approved,Rejected', 'comment' => 'nullable|string|max:1000']);
        abort_unless($adjustment->status === 'Pending', 422, 'คำขอนี้ได้รับการพิจารณาไปแล้ว');
        abort_if($adjustment->requested_by === $r->user()->id, 403, 'ห้ามพิจารณาคำขอของตนเอง');

        $leave = DB::transaction(function () use ($r, $adjustment, $d, $policies) {
            $adjustment = LeaveAdjustment::whereKey($adjustment->id)->lockForUpdate()->first();
            $leave = Leave::whereKey($adjustment->leave_id)->lockForUpdate()->first();
            if ($d['status'] === 'Approved') {
                abort_unless($leave && $leave->status === 'Approved', 422, 'ใบลาไม่ได้อยู่ในสถานะอนุมัติแล้ว');
                abort_unless((float) $adjustment->days < (float) $leave->total_days, 422, 'จำนวนวันที่ขอยกเลิกไม่น้อยกว่าวันลาคงเหลือของใบนี้');
                $balance = LeaveBalance::where('user_id', $leave->user_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $policies->periodYearForLeave($leave))->lockForUpdate()->first();
                if ($balance) {
                    $balance->used = max(0, (float) $balance->used - (float) $adjustment->days);
                    $balance->save();
                }
                // ตัดวันที่ไม่ได้รับเงินเดือนออกก่อน เพราะเป็นส่วนที่เกินสิทธิ์
                $unpaidCut = min((float) $leave->unpaid_days, (float) $adjustment->days);
                $leave->unpaid_days = (float) $leave->unpaid_days - $unpaidCut;
                $leave->paid_days = max(0, (float) $leave->paid_days - ((float) $adjustment->days - $unpaidCut));
                $leave->total_days -= $adjustment->days;
                $leave->save();
            }
            $adjustment->update(['status' => $d['status'], 'approved_by' => $r->user()->id, 'decided_at' => now()]);
            LeaveAuditLog::create(['leave_id' => $adjustment->leave_id, 'user_id' => $r->user()->id, 'action' => 'partial_cancellation_'.$d['status'], 'after' => $adjustment->toArray() + ['comment' => $d['comment'] ?? null], 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);

            return $leave;
        });

        if ($leave) {
            app(LeaveRequestNotificationService::class)->notifyRequester(
                $leave->load('user'),
                $d['status'] === 'Approved' ? 'leave.cancellation_approved' : 'leave.cancellation_rejected',
                $r->user(),
            );
        }

        return $adjustment->fresh();
    }
}
