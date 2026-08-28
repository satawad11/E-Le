<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\Workflow\LeaveApprovalCoordinator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * การยกเลิกใบลา
 *
 * ใบลาที่ยัง "รออนุมัติ" เจ้าของยกเลิกเองได้ทันที
 * ส่วนใบลาที่ "อนุมัติแล้ว" ต้องยื่นคำขอยกเลิกและเดินสายอนุมัติเหมือนตอนขอลา
 *
 * สายอนุมัติของคำขอยกเลิกใช้ขั้นตอนชุดเดียวกับการขอลา แต่ผนึก snapshot ของตัวเอง
 * ณ วันที่ขอยกเลิก และเดินระดับผ่าน `leaves.cancellation_approval_level`
 * เพื่อไม่ให้ทับร่องรอยว่าใบลาเดิมผ่านอนุมัติมาถึงขั้นไหน
 */
class LeaveCancellationController extends Controller
{
    public function __construct(
        private LeaveService $leaveService,
        private LeaveApprovalCoordinator $coordinator,
    ) {}

    public function cancel(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isAdminOrHR = $user->can('leave.management.manage') || $user->can('system.user.view');
        if ($leave->user_id !== $user->id && ! $isAdminOrHR) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ยกเลิกใบลานี้'], 403);
        }
        if ($leave->status === 'Pending') {
            $this->leaveService->restoreBalance($leave);
            $leave->status = 'Cancelled';
            $leave->save();
            LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Cancelled', 'details' => ['reason' => 'User cancelled pending leave']]);

            return response()->json(['message' => 'ยกเลิกใบลาเรียบร้อยแล้ว']);
        }
        if ($leave->status === 'Approved') {
            // กันการยื่นซ้ำทับคำขอเดิมที่ยังรออนุมัติอยู่ (เหตุผลและวันที่จะถูกเขียนทับ)
            if ($leave->cancellation_status === 'Pending') {
                return response()->json(['message' => 'ใบลานี้มีคำขอยกเลิกที่รออนุมัติอยู่แล้ว'], 422);
            }
            $request->validate(['cancellation_reason' => 'required|string|max:1000']);

            // สายของคำขอยกเลิกโดยเฉพาะ — ถ้าผู้ดูแลยังไม่ได้ตั้งค่าไว้ จะได้สายของใบลามาแทน
            $steps = ApprovalStep::stepsFor(ApprovalStep::CHAIN_CANCELLATION);
            if ($steps->isEmpty()) {
                return response()->json(['message' => 'ยังไม่ได้ตั้งค่าลำดับการอนุมัติในระบบ กรุณาตั้งค่าที่เมนูจัดการลำดับการอนุมัติก่อน'], 422);
            }

            DB::transaction(function () use ($leave, $user, $request, $steps) {
                /*
                 * ผนึกสายอนุมัติของคำขอยกเลิก ณ วันที่ขอ — คนละชุดกับ snapshot ของการขอลา
                 * แก้สายอนุมัติภายหลังจึงไม่กระทบคำขอยกเลิกที่ยื่นไปแล้ว
                 *
                 * ล้างของรอบก่อนทิ้งได้ เพราะจะมาถึงบรรทัดนี้ได้ก็ต่อเมื่อคำขอรอบก่อน
                 * ถูกปฏิเสธไปแล้ว (รอบที่ยังค้างถูกกันไว้ด้านบน) และประวัติการตัดสิน
                 * ยังอยู่ครบใน `leave_action_logs` กับ `leave_audit_logs`
                 */
                $leave->cancellationApprovals()->delete();

                foreach ($steps as $step) {
                    LeaveApproval::create([
                        'leave_id' => $leave->id,
                        'stage' => LeaveApproval::STAGE_CANCELLATION,
                        'step_order' => $step->step_order,
                        'step_name' => $step->name,
                        'required_role_id' => $step->role_id,
                        'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                        'specific_user_id' => $step->specific_user_id,
                        'permission_name' => $step->permission_name,
                        'status' => 'Pending',
                    ]);
                }

                $leave->cancellation_status = 'Pending';
                $leave->cancellation_reason = $request->cancellation_reason;
                $leave->cancellation_date = now();
                // ห้ามแตะ approval_level ของใบลาเดิม — เป็นประวัติว่าใบลาผ่านอนุมัติมาถึงขั้นใด
                $leave->cancellation_approval_level = $steps->first()->step_order;
                $leave->save();

                LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Cancellation Requested', 'details' => ['reason' => $request->cancellation_reason]]);
            }, 5);

            return response()->json(['message' => 'ส่งคำขอยกเลิกใบลาที่อนุมัติแล้วเรียบร้อย กรุณารอการอนุมัติยกเลิก']);
        }

        return response()->json(['message' => 'ไม่สามารถยกเลิกใบลานี้ได้'], 422);
    }

    public function approve(Request $request, Leave $leave)
    {
        return $this->act($request, $leave, 'Approved');
    }

    public function reject(Request $request, Leave $leave)
    {
        return $this->act($request, $leave, 'Rejected');
    }

    /**
     * เดินสายอนุมัติของคำขอยกเลิกหนึ่งขั้น
     *
     * อนุมัติ → ไปขั้นถัดไป หรือจบสาย (คืนวันลาแล้วปิดใบลาเป็น Cancelled) เมื่อเป็นขั้นสุดท้าย
     * ไม่อนุมัติ → จบสายทันทีทุกขั้น ใบลาเดิมยังคงสถานะ Approved ตามเดิม
     */
    private function act(Request $request, Leave $leave, string $decision)
    {
        $data = $request->validate([
            'comment' => 'nullable|string|max:1000',
            'signature_base64' => 'nullable|string',
        ]);

        $user = $request->user();
        $isLeaveAdmin = $user->can('leave.management.manage');
        $isApprove = $decision === 'Approved';

        if ($leave->cancellation_status !== 'Pending') {
            return response()->json(['message' => 'ใบลานี้ไม่มีคำขอยกเลิกที่รออนุมัติ'], 422);
        }
        if (! $isLeaveAdmin && $leave->user_id === $user->id) {
            return response()->json([
                'message' => $isApprove ? 'ห้ามอนุมัติคำขอยกเลิกใบลาของตนเอง' : 'ห้ามปฏิเสธคำขอยกเลิกใบลาของตนเอง',
            ], 403);
        }

        // ใบเก่าที่ยังไม่มี snapshot ของสายยกเลิก ต้องสร้างย้อนหลังก่อน engine จะเดินได้
        if ($this->currentStep($leave) === null) {
            return response()->json(['message' => 'ไม่พบขั้นตอนอนุมัติของคำขอยกเลิก กรุณาตรวจสอบการตั้งค่าลำดับการอนุมัติ'], 422);
        }

        $signaturePath = $this->resolveSignature($user, $data['signature_base64'] ?? null);

        DB::transaction(function () use ($leave, $user, $decision, $data, $signaturePath, $isApprove, $request) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();

            /*
             * CLEANUP-02 (D4) — สายยกเลิกตัดสินด้วยผู้ตัดสินคนเดียวกับสายขอลา
             *
             * เดิมตรงนี้เรียก `ApproverResolverService` และไล่ขั้นเองทั้งหมด ซึ่งเป็น
             * ตรรกะเดินสายชุดที่สองที่ไม่เคยถูกครอบด้วย shadow เลย เอกสารปิดเฟส
             * LEGACY-05 ระบุว่าต้องรวมเข้า engine ก่อนจึงจะ cutover ได้
             */
            $outcome = $this->coordinator->decide(
                $locked,
                $isApprove ? 'approve' : 'reject',
                $user,
                $data['comment'] ?? null,
                LeaveApproval::STAGE_CANCELLATION,
            );

            $step = $outcome['step'];

            $step->update([
                'approver_id' => $user->id,
                'approver_name' => $user->full_name_with_title,
                'approver_position' => $user->profile?->position,
                'signature_path' => $signaturePath,
                'status' => $decision,
                'comment' => $data['comment'] ?? null,
                'approved_at' => now(),
                'acted_at' => now(),
                'delegated_from_user_id' => $outcome['delegated_from'],
            ]);

            $next = $isApprove && ! $outcome['final'] ? $outcome['next_step_order'] : null;

            if (! $isApprove) {
                $locked->cancellation_status = 'Rejected';
                $action = 'Cancellation Rejected';
            } elseif ($next) {
                $locked->cancellation_approval_level = $next;
                $action = 'Cancellation Step Approved';
            } else {
                /*
                 * คืนวันลา "ก่อน" เปลี่ยนสถานะ — restoreBalance คืนโควตาที่ใช้ไปแล้ว
                 * เฉพาะใบที่ยังเป็น Approved ถ้าปิดใบเป็น Cancelled ก่อน วันลาจะไม่ถูกคืน
                 * (พฤติกรรมเดิมสลับลำดับไว้ ทำให้อนุมัติยกเลิกแล้ววันลาหายไปเลย)
                 */
                $this->leaveService->restoreBalance($locked);
                $locked->cancellation_status = 'Approved';
                $locked->status = 'Cancelled';
                $action = 'Cancellation Approved';
            }

            $locked->save();

            LeaveActionLog::create([
                'leave_id' => $locked->id,
                'user_id' => $user->id,
                'action' => $action.' (Level '.$step->step_order.')',
                'details' => ! empty($data['comment']) ? ['comment' => $data['comment']] : null,
            ]);

            LeaveAuditLog::create([
                'leave_id' => $locked->id,
                'user_id' => $user->id,
                'action' => $isApprove ? 'cancellation_approved' : 'cancellation_rejected',
                'after' => ['step' => $step->step_order, 'final' => $isApprove ? $outcome['final'] : true],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }, 5);

        $leave->refresh();

        return response()->json([
            'message' => $isApprove ? 'อนุมัติการยกเลิกใบลาเรียบร้อยแล้ว' : 'ปฏิเสธการยกเลิกใบลาเรียบร้อยแล้ว',
            'cancellation_status' => $leave->cancellation_status,
        ]);
    }

    /**
     * ขั้นตอนปัจจุบันของสายอนุมัติคำขอยกเลิก
     *
     * คำขอที่ยื่นไว้ก่อนระบบมีสายอนุมัติของตัวเอง จะถูกสร้าง snapshot ย้อนหลังจาก
     * สายอนุมัติปัจจุบัน เพื่อให้คำขอที่ค้างอยู่เดินต่อได้โดยไม่ต้องยื่นใหม่
     */
    private function currentStep(Leave $leave): ?LeaveApproval
    {
        $level = $leave->cancellation_approval_level ?: $leave->approval_level;

        $step = $leave->cancellationApprovals()->where('step_order', $level)->first();
        if ($step) {
            return $step;
        }

        $legacy = ApprovalStep::stepsFor(ApprovalStep::CHAIN_CANCELLATION)->firstWhere('step_order', $level);
        if ($legacy === null) {
            return null;
        }

        return LeaveApproval::firstOrCreate([
            'leave_id' => $leave->id,
            'stage' => LeaveApproval::STAGE_CANCELLATION,
            'step_order' => $legacy->step_order,
        ], [
            'step_name' => $legacy->name,
            'required_role_id' => $legacy->role_id,
            'approver_type' => $legacy->approver_type ?: ApprovalStep::RESOLVER_ROLE,
            'specific_user_id' => $legacy->specific_user_id,
            'permission_name' => $legacy->permission_name,
            'status' => 'Pending',
        ]);
    }

    /** ลายเซ็นที่จะประทับบนขั้นตอนนี้ — ที่วาดสดมาก่อน ถ้าไม่มีใช้ลายเซ็นในโปรไฟล์ */
    private function resolveSignature(User $user, ?string $base64): ?string
    {
        if (! $base64 || ! preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
            return $user->profile?->signature_path;
        }

        $type = strtolower($matches[1]);
        if (! in_array($type, ['jpg', 'jpeg', 'png'], true)) {
            return $user->profile?->signature_path;
        }

        $binary = base64_decode(substr($base64, strpos($base64, ',') + 1));
        $fileName = 'signatures/'.uniqid().'.'.$type;
        Storage::disk('private_uploads')->put($fileName, $binary);

        return '/storage/'.$fileName;
    }
}
