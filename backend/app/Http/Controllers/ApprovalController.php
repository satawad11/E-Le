<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\User;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
    public function __construct(private readonly ApproverResolverService $resolver) {}

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
        // ใบลาเก่าที่ยื่นก่อนระบบเก็บ snapshot ต้องถอยไปอ่านสายอนุมัติปัจจุบัน
        $legacySteps = ApprovalStep::orderBy('step_order')->get();

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
    private function canActAtCurrentStep(User $user, Leave $leave, Collection $legacySteps, array &$cache): bool
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

        $step = $snapshot ?? $legacySteps->firstWhere('step_order', $level);

        if ($step === null || $leave->user === null) {
            return false;
        }

        $cacheKey = implode('|', [
            $user->id,
            $step->approver_type ?? ApprovalStep::RESOLVER_ROLE,
            $step->required_role_id ?? $step->role_id ?? '-',
            $step->specific_user_id ?? '-',
            $step->permission_name ?? '-',
            $leave->user_id,
        ]);

        return $cache[$cacheKey] ??= $this->resolver->isAuthorizedApprover($user, $leave->user, $step);
    }
}
