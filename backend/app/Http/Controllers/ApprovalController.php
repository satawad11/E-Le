<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Services\Workflow\LeaveApprovalCoordinator;
use Illuminate\Http\Request;

/**
 * รายการใบลาที่ "ฉัน" ต้องอนุมัติ
 *
 * กติกาข้อเดียวของหน้านี้: เห็น = อนุมัติได้
 *
 * เดิมรายการนี้คัดด้วยการจับคู่ role ของผู้ใช้กับ `approval_steps.role_id` ตรง ๆ
 * ซึ่งเป็นคนละตรรกะกับตอนกดอนุมัติจริง (ที่ใช้ ApproverResolverService)
 * ผลคือหัวหน้าแผนกต้นสังกัดซึ่งเป็นผู้อนุมัติตัวจริงไม่เห็นใบลาของลูกทีมเลย
 * ส่วนผู้ถือ role ที่ถูกเติมให้เงียบ ๆ กลับเห็นใบลาที่ตนไม่มีสิทธิ์อนุมัติ
 *
 * การอนุมัติ/ปฏิเสธจริงอยู่ที่ GovernmentApprovalController (routes/leave_government.php)
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly LeaveApprovalCoordinator $coordinator) {}

    public function pending(Request $request)
    {
        $user = $request->user();

        $query = Leave::with(['user.profile', 'user.department', 'type:id,name', 'approvals.approver:id,name', 'cancellationApprovals.approver:id,name'])
            ->where(function ($sub) {
                $sub->where('status', 'Pending')
                    ->orWhere('cancellation_status', 'Pending');
            })
            ->orderBy('created_at', 'asc');

        // ผู้ดูแลระบบการลาเห็นทุกใบเหมือนเดิม
        if ($user->can('leave.management.manage')) {
            return response()->json($query->get());
        }

        /*
         * ไม่หรี่ด้วยขอบเขตหน่วยงาน (LeaveVisibility) ตรงนี้โดยตั้งใจ
         *
         * ขอบเขตหน่วยงานมีไว้ตอบว่า "เปิดดูใบลาของใครได้" ส่วนหน้านี้ตอบว่า
         * "ต้องอนุมัติใบไหน" ซึ่งแคบกว่ามากอยู่แล้วเพราะผ่าน isAuthorizedApprover
         * ถ้าเอาขอบเขตมาครอบซ้ำ ผู้อนุมัติข้ามสายงาน (เช่น ผอ. หรือผู้ที่ถูกระบุ
         * เป็นผู้อนุมัติเฉพาะราย) จะไม่เห็นงานที่ตนต้องอนุมัติ
         *
         * จำผลการตรวจสิทธิ์ต่อ (ขั้นตอน + ผู้ยื่น) เพื่อไม่ให้ยิง query ซ้ำทั้งรายการ
         *
         * ตัวแปรนี้ต้องเป็นของ "คำขอนี้" เท่านั้น ห้ามเก็บไว้บนคอนโทรลเลอร์
         * เพราะ Laravel จำอินสแตนซ์คอนโทรลเลอร์ไว้กับ route ผลของผู้ใช้คนก่อน
         * จะรั่วไปให้ผู้ใช้คนถัดไปเห็นใบลาที่ตนไม่มีสิทธิ์อนุมัติ
         */
        $cache = [];
        // ใบลาเก่าที่ยื่นก่อนระบบเก็บ snapshot ต้องถอยไปอ่านสายอนุมัติปัจจุบัน — คนละสายกัน
        $legacySteps = [
            ApprovalStep::CHAIN_LEAVE => ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE),
            ApprovalStep::CHAIN_CANCELLATION => ApprovalStep::stepsFor(ApprovalStep::CHAIN_CANCELLATION),
        ];

        $leaves = $query
            // ห้ามอนุมัติใบลาของตนเอง จึงไม่ต้องแสดงในรายการที่ต้องอนุมัติ
            ->where('user_id', '!=', $user->id)
            ->get()
            ->filter(fn (Leave $leave) => $this->canActAtCurrentStep($user, $leave, $legacySteps, $cache))
            ->values();

        return response()->json($leaves);
    }

    /**
     * ผู้ใช้คนนี้อนุมัติใบลาฉบับนี้ "ในขั้นตอนปัจจุบัน" ได้หรือไม่
     *
     * ใช้ snapshot ของใบลาเป็นหลัก (กติกา ณ วันยื่น) และถอยไปใช้สายอนุมัติปัจจุบัน
     * เฉพาะใบเก่าที่ยังไม่มี snapshot — ตรงกับที่ GovernmentApprovalController ทำ
     */
    private function canActAtCurrentStep(User $user, Leave $leave, array $legacySteps, array &$cache): bool
    {
        /*
         * ใบที่มีคำขอยกเลิกรออนุมัติ ต้องถามสายอนุมัติ "ของคำขอยกเลิก" ไม่ใช่สายของการขอลา
         * (สายขอลาอนุมัติจบไปแล้ว ทุกขั้นเป็น Approved การถามสายนั้นจึงตอบผิดคน)
         */
        $isCancellation = $leave->cancellation_status === 'Pending';

        $level = $isCancellation
            ? ($leave->cancellation_approval_level ?: $leave->approval_level)
            : $leave->approval_level;

        $snapshot = $isCancellation
            ? $leave->cancellationApprovals->firstWhere('step_order', $level)
            : $leave->approvals->firstWhere('step_order', $level);

        $chain = $isCancellation ? ApprovalStep::CHAIN_CANCELLATION : ApprovalStep::CHAIN_LEAVE;
        $step = $snapshot ?? $legacySteps[$chain]->firstWhere('step_order', $level);

        if ($step === null || $leave->user === null) {
            return false;
        }

        /*
         * CLEANUP-01 / CLEANUP-02 (D4) — ถามผู้ตัดสินคนเดียวกับตอนกดอนุมัติจริง
         *
         * เดิมหน้านี้เรียก `ApproverResolverService` เอง ถ้าวันหนึ่ง engine กลายเป็น
         * ผู้ตัดสิน (`workflow.engine_is_source_of_truth`) รายการนี้จะยังตอบด้วยกติกาเดิม
         * ผู้ใช้จึงเห็นใบลาที่กดแล้วถูกปฏิเสธ หรือไม่เห็นใบที่ตนอนุมัติได้จริง
         *
         * ใบเก่าที่ยังไม่มี snapshot ส่งขั้นที่หามาได้เข้าไปตรง ๆ เพราะ engine
         * สร้าง instance จากแถวสรุปไม่ได้เมื่อยังไม่มีแถวนั้น
         */
        $stage = $isCancellation ? LeaveApproval::STAGE_CANCELLATION : LeaveApproval::STAGE_REQUEST;

        $cacheKey = implode('|', [
            $user->id,
            $step->approver_type ?? ApprovalStep::RESOLVER_ROLE,
            $step->required_role_id ?? $step->role_id ?? '-',
            $step->specific_user_id ?? '-',
            $step->permission_name ?? '-',
            $leave->user_id,
            $stage,
        ]);

        return $cache[$cacheKey] ??= $this->coordinator->canAct(
            $leave,
            $user,
            $snapshot === null ? $step : null,
            $stage,
        );
    }
}
