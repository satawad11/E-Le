<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\User;
use App\Services\LeaveApprovalFlow;
use App\Services\LeaveApprovalSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * รายการใบลา/คำขอยกเลิกที่รอผู้ใช้คนนี้พิจารณา — กติกาข้อเดียว: เห็น = อนุมัติได้
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

        $query = Leave::with(['user.profile', 'user.department', 'type:id,name', 'approvals.approver:id,name', 'cancellationApprovals.approver:id,name'])
            ->where(function ($query) {
                $query->where('status', 'Pending')
                    ->orWhere(fn ($q) => $q->where('status', 'Approved')->where('cancellation_status', 'Pending'));
            })
            ->orderBy('created_at', 'asc');

        if ($user->can('leave.management.manage')) {
            return response()->json($this->withSigning($user, $query->get()));
        }

        /*
         * เดิมกรองด้วย approval_steps.role_id ของ role ผู้ใช้ ขั้นที่ตั้งผู้อนุมัติเป็น
         * หัวหน้าหน่วยงาน / ผู้อำนวยการ / รายบุคคล (role_id ว่าง) จึงไม่เคยขึ้นในรายการ
         * ตอนนี้ถาม ApproverResolverService ผ่าน LeaveApprovalFlow ซึ่งเป็นตัวเดียวกับ
         * ที่ใช้ตรวจสิทธิ์ตอนกดอนุมัติ — สิ่งที่เห็นในรายการจึงตรงกับสิ่งที่กดได้จริง
         * ใบที่รอคำขอยกเลิกถามสายของคำขอยกเลิก ไม่ใช่สายของการขอลาที่อนุมัติจบไปแล้ว
         * ใบลาของตนเองขึ้นเฉพาะขั้นที่อนุมัติของตนเองได้ (LEAVE-APPROVAL-02)
         *
         * ไม่หรี่ด้วยขอบเขตหน่วยงาน (LeaveVisibility) โดยตั้งใจ — ขอบเขตหน่วยงานตอบว่า
         * "เปิดดูใบลาของใครได้" ส่วนหน้านี้ตอบว่า "ต้องอนุมัติใบไหน" ถ้าครอบซ้ำ
         * ผู้อนุมัติข้ามสายงาน (เช่น ผอ. หรือผู้ที่ถูกระบุเป็นผู้อนุมัติเฉพาะราย) จะไม่เห็นงานของตน
         */
        $leaves = $query->get()
            ->filter(fn (Leave $leave) => $this->flow->canActAtCurrentStep($user, $leave))
            ->values();

        return response()->json($this->withSigning($user, $leaves));
    }

    /**
     * แนบ "ฐานะการลงนาม" ของผู้ใช้ในขั้นปัจจุบันของแต่ละใบ (DELEGATION-01)
     *
     * หน้าจอใช้แสดงว่า "ท่านลงนามในฐานะปฏิบัติราชการแทน …" หรือให้ผู้ดูแลเลือกผู้มีอำนาจตัวจริงเมื่อบันทึกแทน
     *
     * @param  Collection<int, Leave>  $leaves
     * @return Collection<int, Leave>
     */
    private function withSigning(User $user, Collection $leaves): Collection
    {
        $signer = app(LeaveApprovalSigner::class);

        return $leaves->each(function (Leave $leave) use ($user, $signer) {
            $step = $this->flow->currentStep($leave);
            $leave->setAttribute('signing', $step === null ? null : $signer->describe($user, $leave, $step));
        })->values();
    }
}
