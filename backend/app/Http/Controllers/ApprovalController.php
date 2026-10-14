<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\LeaveApprovalFlow;
use Illuminate\Http\Request;

/**
 * รายการใบลา/คำขอยกเลิกที่รอผู้ใช้คนนี้พิจารณา
 *
 * การอนุมัติจริงอยู่ที่ GovernmentApprovalController (routes/leave_government.php)
 * และการยกเลิกวันลาอยู่ที่ LeaveCancellationController — เมธอด approve/reject
 * ชุดเก่าที่เคยอยู่ในไฟล์นี้ไม่มี route ชี้มาแล้วจึงถูกลบออก
 */
class ApprovalController extends Controller
{
    public function __construct(private LeaveApprovalFlow $flow) {}

    public function pending(Request $request)
    {
        $user = $request->user();

        $query = Leave::with(['user.profile', 'user.department', 'type:id,name', 'approvals.approver:id,name'])
            ->where(function ($query) {
                $query->where('status', 'Pending')
                    ->orWhere(fn ($q) => $q->where('status', 'Approved')->where('cancellation_status', 'Pending'));
            })
            ->orderBy('created_at', 'asc');

        if ($user->can('leave.management.manage')) {
            return response()->json($query->get());
        }

        /*
         * เดิมกรองด้วย approval_steps.role_id ของ role ผู้ใช้ ขั้นที่ตั้งผู้อนุมัติเป็น
         * หัวหน้าหน่วยงาน / ผู้อำนวยการ / รายบุคคล (role_id ว่าง) จึงไม่เคยขึ้นในรายการ
         * ตอนนี้ถาม ApproverResolverService ผ่าน LeaveApprovalFlow ซึ่งเป็นตัวเดียวกับ
         * ที่ใช้ตรวจสิทธิ์ตอนกดอนุมัติ — สิ่งที่เห็นในรายการจึงตรงกับสิ่งที่กดได้จริง
         * ใบลาของตนเองขึ้นเฉพาะขั้นที่อนุมัติของตนเองได้ (LEAVE-APPROVAL-02)
         */
        $leaves = $query->get()
            ->filter(fn (Leave $leave) => $this->flow->canActAtCurrentStep($user, $leave))
            ->values();

        return response()->json($leaves);
    }
}
