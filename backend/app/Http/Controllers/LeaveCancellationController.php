<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Services\LeaveApprovalFlow;
use App\Services\LeaveApprovalSigner;
use App\Services\LeaveRequestNotificationService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ยกเลิกวันลา (แบบใบขอยกเลิกวันลา)
 *
 * - ใบที่ยังรออนุมัติ: ผู้ยื่นยกเลิกเองได้ทันที คืนยอดที่จองไว้
 * - ใบที่อนุมัติแล้ว: ต้องยื่นคำขอยกเลิก แล้วเดินสายอนุมัติของคำขอยกเลิก
 *   (ตั้งแยกได้ที่หน้าจัดการลำดับการอนุมัติ ถ้ายังไม่ตั้งจะผ่านทุกขั้นตอนเดิมที่ใบลานั้นผ่านมา)
 *   เมื่อขั้นสุดท้ายอนุมัติ ใบลาเป็น Cancelled และคืนวันลาที่ใช้ไป
 *
 * สายของคำขอยกเลิกผนึก snapshot ของตัวเอง (stage = cancellation) ณ วันที่ขอ
 * และเดินระดับผ่าน `leaves.cancellation_approval_level` — ไม่ทับ approval_level
 * ซึ่งเป็นร่องรอยว่าใบลาเดิมผ่านอนุมัติมาถึงขั้นไหน
 */
class LeaveCancellationController extends Controller
{
    public function __construct(
        private LeaveService $leaveService,
        private LeaveApprovalFlow $flow,
        private LeaveRequestNotificationService $notifications,
        private LeaveApprovalSigner $signer,
    ) {}

    public function cancel(Request $request, Leave $leave)
    {
        $user = $request->user();
        $isAdminOrHR = $user->can('leave.management.manage') || $user->can('system.user.view');
        if ($leave->user_id !== $user->id && ! $isAdminOrHR) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ยกเลิกใบลานี้'], 403);
        }

        if ($leave->status === 'Pending') {
            DB::transaction(function () use ($leave, $user, $request) {
                $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === 'Pending', 422, 'สถานะใบลาเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');
                $this->leaveService->restoreBalance($locked);
                $locked->forceFill(['status' => 'Cancelled', 'cancellation_reason' => $request->input('cancellation_reason'), 'cancellation_date' => now()])->save();
                LeaveActionLog::create(['leave_id' => $locked->id, 'user_id' => $user->id, 'action' => 'Cancelled', 'details' => ['reason' => $request->input('cancellation_reason') ?: 'ยกเลิกใบลาที่ยังรออนุมัติ']]);
                $this->audit($request, $locked, 'cancelled_pending');
            });

            return response()->json(['message' => 'ยกเลิกใบลาเรียบร้อยแล้ว']);
        }

        if ($leave->status === 'Approved') {
            $request->validate(['cancellation_reason' => 'required|string|max:1000']);

            $steps = $this->cancellationSteps($leave);
            if ($steps->isEmpty()) {
                return response()->json(['message' => 'ยังไม่ได้ตั้งค่าลำดับการอนุมัติในระบบ กรุณาตั้งค่าที่เมนูจัดการลำดับการอนุมัติก่อน'], 422);
            }

            $locked = DB::transaction(function () use ($leave, $user, $request, $steps) {
                $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === 'Approved', 422, 'สถานะใบลาเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');
                // กันการยื่นซ้ำทับคำขอเดิมที่ยังรอพิจารณาอยู่ (เหตุผลและวันที่จะถูกเขียนทับ)
                abort_if($locked->cancellation_status === 'Pending', 422, 'ใบลานี้มีคำขอยกเลิกที่รอพิจารณาอยู่แล้ว');

                /*
                 * ล้างของรอบก่อนทิ้งได้ เพราะจะมาถึงบรรทัดนี้ได้ก็ต่อเมื่อคำขอรอบก่อน
                 * ถูกปฏิเสธไปแล้ว และประวัติการตัดสินยังอยู่ครบใน leave_action_logs / leave_audit_logs
                 */
                $locked->cancellationApprovals()->delete();
                foreach ($steps as $step) {
                    LeaveApproval::create($step + [
                        'leave_id' => $locked->id,
                        'stage' => LeaveApproval::STAGE_CANCELLATION,
                        'status' => 'Pending',
                    ]);
                }

                $locked->forceFill([
                    'cancellation_status' => 'Pending',
                    'cancellation_reason' => $request->cancellation_reason,
                    'cancellation_date' => now(),
                    'cancellation_approval_level' => $steps->first()['step_order'],
                ])->save();
                LeaveActionLog::create(['leave_id' => $locked->id, 'user_id' => $user->id, 'action' => 'Cancellation Requested', 'details' => ['reason' => $request->cancellation_reason]]);
                $this->audit($request, $locked, 'cancellation_requested');

                return $locked;
            }, 5);
            $this->notifications->notifyCurrentStep($locked->fresh(['user']), 'leave.cancellation_requested', $user);

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
     * ไม่อนุมัติ → จบสายทันที ใบลาเดิมยังคงสถานะ Approved
     */
    private function act(Request $request, Leave $leave, string $decision)
    {
        $isApprove = $decision === 'Approved';
        $data = $request->validate([
            'comment' => $isApprove ? 'nullable|string|max:1000' : 'required|string|max:1000',
            'signature_base64' => 'nullable|string|max:2000000',
            'on_behalf_of' => 'nullable|integer|exists:users,id',
        ]);
        $user = $request->user();

        [$locked, $next] = DB::transaction(function () use ($request, $leave, $user, $data, $decision, $isApprove) {
            $locked = Leave::with('user')->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->flow->awaitsCancellation($locked), 422, 'ใบลานี้ไม่มีคำขอยกเลิกที่รอพิจารณา');

            $step = $this->currentStep($locked);
            abort_if($step === null, 422, 'ไม่พบขั้นตอนอนุมัติของคำขอยกเลิก กรุณาตรวจสอบการตั้งค่าลำดับการอนุมัติ');

            // ฐานะการลงนาม: ตนเอง / ผู้มีอำนาจ / ทำหน้าที่แทน / ผู้ดูแลบันทึกแทน (DELEGATION-01)
            $capacity = $this->signer->capacity($user, $locked, $step, $isApprove, $request->integer('on_behalf_of') ?: null);
            $isSelf = $capacity['mode'] === LeaveApprovalSigner::MODE_SELF;
            $delegatedFrom = $capacity['delegation']?->user_id;

            $step->update(['status' => $decision] + $this->signer->snapshot($capacity, $user, $data['signature_base64'] ?? null, $data['comment'] ?? null));

            $next = $isApprove
                ? $locked->cancellationApprovals()->where('step_order', '>', $step->step_order)->orderBy('step_order')->first()
                : null;

            if (! $isApprove) {
                $locked->forceFill(['cancellation_status' => 'Rejected'])->save();
                $action = 'Cancellation Rejected';
            } elseif ($next) {
                $locked->forceFill(['cancellation_approval_level' => $next->step_order])->save();
                $action = 'Cancellation Step Approved';
            } else {
                // คืนวันลา **ก่อน** เปลี่ยนสถานะ — ถ้าปิดใบเป็น Cancelled ก่อน restoreBalance
                // จะไม่รู้ว่าเป็นใบที่อนุมัติแล้ว และไม่คืนวันลาเลย
                $this->leaveService->restoreBalance($locked, true);
                $locked->forceFill(['cancellation_status' => 'Approved', 'status' => 'Cancelled'])->save();
                $action = 'Cancellation Approved';
            }

            LeaveActionLog::create([
                'leave_id' => $locked->id, 'user_id' => $user->id, 'action' => $action.' (Level '.$step->step_order.')',
                'details' => array_filter(['comment' => $data['comment'] ?? null, 'delegated_from' => $delegatedFrom, 'self_approved' => $isSelf ?: null]) ?: null,
            ]);
            $this->audit($request, $locked, match (true) {
                ! $isApprove => 'cancellation_rejected',
                $next !== null => 'cancellation_step_approved',
                default => 'cancellation_approved',
            }, ['step' => $step->step_order, 'final' => $next === null, 'comment' => $data['comment'] ?? null, 'self_approved' => $isSelf, 'capacity' => $capacity['mode'], 'signer_id' => $capacity['signer']->id, 'delegated_from' => $delegatedFrom]);

            return [$locked, $next];
        }, 5);

        if (! $isApprove) {
            $this->notifications->notifyRequester($locked, 'leave.cancellation_rejected', $user);
        } elseif ($next === null) {
            $this->notifications->notifyRequester($locked, 'leave.cancellation_approved', $user);
        } else {
            $this->notifications->notifyCurrentStep($locked->fresh(['user']), 'leave.cancellation_requested', $user);
        }

        return response()->json([
            'message' => match (true) {
                ! $isApprove => 'ปฏิเสธการยกเลิกใบลาเรียบร้อยแล้ว',
                $next === null => 'อนุมัติการยกเลิกใบลาเรียบร้อยแล้ว คืนวันลาให้ผู้ยื่นแล้ว',
                default => 'อนุมัติการยกเลิกในขั้นนี้แล้ว ส่งต่อขั้นถัดไป',
            },
            'cancellation_status' => $locked->cancellation_status,
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
        $level = (int) ($leave->cancellation_approval_level ?: $leave->approval_level);

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
        ], $legacy->snapshotAttributes() + ['status' => 'Pending']);
    }

    /**
     * ขั้นตอนของคำขอยกเลิกใบลาที่อนุมัติแล้ว (ACCESS-GOV-01 · Q4)
     *
     * - ผู้ดูแลตั้งสายยกเลิกแยกไว้ → ใช้สายนั้น
     * - ไม่ได้ตั้ง → "ผ่านทุกขั้นตอนเดิม" คือคัดลอกขั้นที่ใบลานี้ถูก pin ไว้ตอนยื่น
     *   ไม่ใช่สายของใบลาในวันนี้ ซึ่งอาจถูกแก้ไปแล้วหลังใบลาอนุมัติ
     * - ใบลาเก่าที่ไม่มี snapshot → ใช้สายของใบลาปัจจุบัน (พฤติกรรมเดิม)
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function cancellationSteps(Leave $leave): Collection
    {
        if (! ApprovalStep::cancellationChainIsConfigured()) {
            $original = $leave->approvals()->orderBy('step_order')->get();
            if ($original->isNotEmpty()) {
                return $original->map(fn (LeaveApproval $step) => [
                    'step_order' => $step->step_order,
                    'step_name' => $step->step_name,
                    'required_role_id' => $step->required_role_id,
                    'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                    'specific_user_id' => $step->specific_user_id,
                    'permission_name' => $step->permission_name,
                    'org_unit_id' => $step->org_unit_id,
                    'allow_self_approval' => (bool) $step->allow_self_approval,
                ])->values();
            }
        }

        return ApprovalStep::stepsFor(ApprovalStep::CHAIN_CANCELLATION)
            ->map(fn (ApprovalStep $step) => ['step_order' => $step->step_order] + $step->snapshotAttributes())
            ->values();
    }

    private function audit(Request $request, Leave $leave, string $action, array $after = []): void
    {
        LeaveAuditLog::create([
            'leave_id' => $leave->id, 'user_id' => $request->user()->id, 'action' => $action,
            'after' => $after + ['status' => $leave->status, 'cancellation_status' => $leave->cancellation_status, 'approval_level' => $leave->approval_level, 'cancellation_approval_level' => $leave->cancellation_approval_level],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
