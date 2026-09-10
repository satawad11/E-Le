<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveAuditLog;
use App\Models\LeaveBalance;
use App\Services\Attendance\LeaveAttendanceSync;
use App\Services\LeavePolicyService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveAdjustmentController extends Controller
{
    public function store(Request $r, Leave $leave, LeaveService $service)
    {
        abort_unless($leave->user_id === $r->user()->id, 403);
        abort_unless($leave->status === 'Approved', 422);
        $d = $r->validate(['cancel_from' => 'required|date|after_or_equal:'.$leave->start_date->toDateString(), 'cancel_to' => 'required|date|after_or_equal:cancel_from|before_or_equal:'.$leave->end_date->toDateString(), 'reason' => 'required|string|max:1000']);

        /*
         * นับวันด้วยตารางเวลาของเจ้าของใบลา ไม่ใช่ปฏิทินระดับองค์กร
         *
         * ตอนยื่นใบลา ระบบส่งทั้งตัวผู้ใช้และหน่วยงานเข้าไปคำนวณ จึงเคารพตารางเวลาเฉพาะบุคคล
         * เดิมตรงนี้ไม่ส่งทั้งสองอย่าง คนที่ทำงานวันเสาร์ตามตารางของตนจึงได้วันคืนไม่เท่ากับ
         * ที่ถูกตัดไปตอนยื่น โควตาเพี้ยนถาวร
         */
        $owner = $leave->user;
        $days = $service->calculateTotalDays(
            $d['cancel_from'],
            $d['cancel_to'],
            $leave->policy?->exclude_holidays ?? true,
            $owner?->org_unit_id,
            $owner,
        );

        /*
         * กันคำขอที่ซ้อนทับกับคำขอเดิมที่ยังไม่ถูกปฏิเสธ
         *
         * เดิมตรวจแค่ว่าช่วงอยู่ในใบลาและน้อยกว่าจำนวนวันทั้งใบ ยื่นสองคำขอครอบวันเดียวกัน
         * แล้วอนุมัติทั้งคู่ ระบบจะลบ `used` สองรอบ ผู้ใช้ได้วันลาคืนเกินจริง
         */
        $overlaps = $leave->adjustments()
            ->whereIn('status', ['Pending', 'Approved'])
            ->where('cancel_from', '<=', $d['cancel_to'])
            ->where('cancel_to', '>=', $d['cancel_from'])
            ->exists();
        abort_if($overlaps, 422, 'ช่วงวันที่ขอยกเลิกซ้อนทับกับคำขอที่ยื่นไว้แล้ว');

        // เทียบกับวันที่ยังเหลืออยู่จริง ไม่ใช่จำนวนวันทั้งใบ — คำขอย่อยหลายรอบต้องรวมกันไม่เกิน
        $alreadyRequested = (float) $leave->adjustments()->whereIn('status', ['Pending', 'Approved'])->sum('days');
        abort_if($days <= 0 || ($days + $alreadyRequested) >= (float) $leave->total_days, 422, 'กรณียกเลิกทั้งหมดให้ใช้คำขอยกเลิกใบลา');

        return response()->json(LeaveAdjustment::create(['leave_id' => $leave->id, 'requested_by' => $r->user()->id, 'cancel_from' => $d['cancel_from'], 'cancel_to' => $d['cancel_to'], 'days' => $days, 'reason' => $d['reason']]), 201);
    }

    public function decide(Request $r, LeaveAdjustment $adjustment, LeavePolicyService $policies, LeaveAttendanceSync $attendanceSync)
    {
        abort_unless($r->user()->can('leave.management.manage') || $r->user()->can('leave.approval.hr'), 403);
        $d = $r->validate(['status' => 'required|in:Approved,Rejected']);
        abort_unless($adjustment->status === 'Pending', 422);
        DB::transaction(function () use ($r, $adjustment, $d, $policies, $attendanceSync) {
            $adjustment = LeaveAdjustment::whereKey($adjustment->id)->lockForUpdate()->first();
            if ($d['status'] === 'Approved') {
                $leave = Leave::whereKey($adjustment->leave_id)->lockForUpdate()->first();
                $balance = LeaveBalance::where('user_id', $leave->user_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $policies->periodYearForLeave($leave))->lockForUpdate()->first();
                if ($balance) {
                    $balance->used = max(0, (float) $balance->used - (float) $adjustment->days);
                    $balance->save();
                }

                /*
                 * ลดจำนวนวันของใบลา พร้อมปรับ "วันที่ได้รับเงินเดือน" ให้สอดคล้อง
                 *
                 * เดิมลดเฉพาะ `total_days` ทำให้ paid + unpaid ไม่เท่ากับจำนวนวันทั้งใบอีกต่อไป
                 * เอกสารและรายงานที่พิมพ์ตัวเลขสองชุดนี้จึงขัดกันเอง
                 *
                 * คืนวันที่ไม่ได้รับเงินเดือนก่อน เพราะเป็นส่วนที่เกินเพดานมาทีหลัง
                 */
                $cancelled = (float) $adjustment->days;
                $unpaidReturned = min((float) $leave->unpaid_days, $cancelled);
                $leave->unpaid_days = (float) $leave->unpaid_days - $unpaidReturned;
                $leave->paid_days = max(0, (float) $leave->paid_days - ($cancelled - $unpaidReturned));
                $leave->total_days = max(0, (float) $leave->total_days - $cancelled);
                $leave->save();

                /*
                 * ล้างเฉพาะช่วงที่ถูกยกเลิกออกจากใบลงเวลา
                 *
                 * ไม่ขยับ `start_date`/`end_date` ของใบลาโดยตั้งใจ — เอกสารที่อนุมัติไปแล้ว
                 * ระบุช่วงวันไว้อย่างไรต้องคงอย่างนั้น การยกเลิกบางช่วงเป็นเอกสารคนละฉบับ
                 */
                $attendanceSync->clear(
                    $leave->load('type'),
                    $adjustment->cancel_from instanceof \DateTimeInterface ? $adjustment->cancel_from->toDateString() : (string) $adjustment->cancel_from,
                    $adjustment->cancel_to instanceof \DateTimeInterface ? $adjustment->cancel_to->toDateString() : (string) $adjustment->cancel_to,
                );
            } $adjustment->update(['status' => $d['status'], 'approved_by' => $r->user()->id, 'decided_at' => now()]);
            LeaveAuditLog::create(['leave_id' => $adjustment->leave_id, 'user_id' => $r->user()->id, 'action' => 'partial_cancellation_'.$d['status'], 'after' => $adjustment->toArray(), 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);
        });

        return $adjustment->fresh();
    }
}
