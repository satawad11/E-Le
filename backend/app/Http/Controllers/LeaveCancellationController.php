<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveApproval;
use App\Models\LeaveAuditLog;
use App\Models\User;
use App\Rules\Base64Image;
use App\Services\Attendance\LeaveAttendanceSync;
use App\Services\Files\ImageUploadService;
use App\Services\LeaveService;
use App\Services\Workflow\LeaveApprovalCoordinator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        private LeaveAttendanceSync $attendanceSync,
        private ImageUploadService $images,
    ) {}

    public function cancel(Request $request, Leave $leave)
    {
        $user = $request->user();

        /*
         * SEC-027 — สิทธิ์ยกเลิกใบลาของผู้อื่นต้องเป็นสิทธิ์จัดการการลาโดยเฉพาะ
         *
         * เดิมยอมรับ `system.user.view` ด้วย ซึ่งเป็นสิทธิ์ "ดูทะเบียนผู้ใช้"
         * คนที่ได้สิทธิ์ไว้เพียงเพื่อเปิดดูรายชื่อบุคลากรจึงยกเลิกใบลาของคนทั้ง
         * องค์กรได้ — เป็นการขยายอำนาจที่ไม่มีใครตั้งใจให้
         *
         * หัวหน้าบุคลากรยังยกเลิกใบลาของผู้อื่นได้เหมือนเดิม แต่ผ่าน
         * `leave.approval.hr` ซึ่งเป็นสิทธิ์งานลาโดยตรงและ `OperationalPermissionSeeder`
         * มอบให้บทบาท `HR Head` อยู่แล้ว ไม่ใช่ผ่านสิทธิ์ดูทะเบียนผู้ใช้
         */
        $canManageLeave = $user->can('leave.management.manage') || $user->can('leave.approval.hr');
        if ($leave->user_id !== $user->id && ! $canManageLeave) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ยกเลิกใบลานี้'], 403);
        }

        /*
         * SEC-027 — กดยกเลิกซ้ำต้องได้ผลลัพธ์เดียวกัน ไม่ใช่ error ที่อธิบายไม่ได้
         *
         * ใบที่ยกเลิกไปแล้วคือ "สภาพปลายทาง" ที่ผู้ใช้ต้องการอยู่แล้ว จึงตอบสำเร็จ
         * โดยไม่แตะโควตาหรือเขียนประวัติซ้ำ
         */
        if ($leave->status === 'Cancelled') {
            return response()->json(['message' => 'ใบลานี้ถูกยกเลิกไปแล้ว']);
        }

        if ($leave->status === 'Pending') {
            return $this->cancelPending($request, $leave);
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

    /**
     * ยกเลิกใบลาที่ยัง "รออนุมัติ" — ทำทั้งหมดใน transaction เดียว
     *
     * SEC-027 — เดิมห้าขั้นตอน (คืนโควตา · ล้างวันลาในตารางลงเวลา · เปลี่ยนสถานะ ·
     * บันทึกประวัติ) วิ่งนอก transaction และไม่ล็อกแถวใบลาเลย กดปุ่มสองครั้งเร็ว ๆ
     * หรือกดจากสองอุปกรณ์พร้อมกัน ทั้งสองคำขออ่านสถานะ `Pending` ได้พร้อมกัน
     * แล้วคืนโควตาคนละครั้ง — วันลาคงเหลือของผู้ใช้เพิ่มขึ้นเป็นสองเท่าอย่างเงียบ ๆ
     * และถ้าขั้นตอนกลางล้ม ใบลาจะค้างอยู่ในสภาพคืนโควตาแล้วแต่สถานะยังไม่เปลี่ยน
     *
     * ตอนนี้ล็อกแถวใบลาก่อน แล้ว "ตรวจสถานะซ้ำหลังได้ล็อก" คำขอที่มาทีหลังจะเห็น
     * สถานะ `Cancelled` แล้วจึงไม่คืนโควตาซ้ำ · ตอบ 200 เหมือนกันเพื่อให้ผลลัพธ์
     * ที่ผู้ใช้เห็นเป็น idempotent (กดซ้ำแล้วไม่ขึ้น error ที่อธิบายไม่ได้)
     */
    private function cancelPending(Request $request, Leave $leave)
    {
        $user = $request->user();

        $outcome = DB::transaction(function () use ($leave, $user, $request): string {
            $locked = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'Pending') {
                // สถานะเปลี่ยนไปแล้วระหว่างรอล็อก — ยกเลิกซ้ำถือว่าสำเร็จ
                // ส่วนสถานะอื่น (เช่นเพิ่งได้รับอนุมัติ) ต้องไม่ถูกยกเลิกเงียบ ๆ
                return $locked->status === 'Cancelled' ? 'already_cancelled' : 'status_changed';
            }

            // คืนโควตา (ล็อกแถวโควตาไว้ด้วยภายในตัวมันเอง) ก่อนเปลี่ยนสถานะเสมอ —
            // restoreBalance ตัดสินจากสถานะปัจจุบันของใบลา
            $this->leaveService->restoreBalance($locked);
            $this->attendanceSync->clear($locked->load('type'));

            $locked->status = 'Cancelled';
            $locked->save();

            LeaveActionLog::create([
                'leave_id' => $locked->id,
                'user_id' => $user->id,
                'action' => 'Cancelled',
                'details' => ['reason' => 'User cancelled pending leave'],
            ]);

            LeaveAuditLog::create([
                'leave_id' => $locked->id,
                'user_id' => $user->id,
                'action' => 'pending_cancelled',
                'before' => ['status' => 'Pending'],
                'after' => ['status' => 'Cancelled'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return 'cancelled';
        }, 5);

        if ($outcome === 'status_changed') {
            return response()->json([
                'message' => 'สถานะใบลาเปลี่ยนไปแล้วระหว่างดำเนินการ กรุณาโหลดข้อมูลใหม่แล้วลองอีกครั้ง',
            ], 422);
        }

        return response()->json([
            'message' => $outcome === 'already_cancelled'
                ? 'ใบลานี้ถูกยกเลิกไปแล้ว'
                : 'ยกเลิกใบลาเรียบร้อยแล้ว',
        ]);
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
            // SEC-028 — allowlist กลาง ตรวจจากเนื้อไฟล์จริง ไม่ใช่จากส่วนหัว data URI
            'signature_base64' => ['nullable', 'string', new Base64Image('signatures')],
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
                // ล้างวันลาออกจากตารางลงเวลาด้วย มิฉะนั้นใบลงเวลารายเดือนยังนับเป็นวันลาต่อไป
                $this->attendanceSync->clear($locked->load('type'));
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

    /**
     * ลายเซ็นที่จะประทับบนขั้นตอนนี้ — ที่วาดสดมาก่อน ถ้าไม่มีใช้ลายเซ็นในโปรไฟล์
     *
     * SEC-028 — เดิมตรวจชนิดไฟล์จากส่วนหัว data URI ที่ client เขียนเอง ไม่จำกัด
     * ขนาด และ decode แบบไม่ strict · ตอนนี้ทั้งการตรวจและการเขียนไฟล์อยู่ที่
     * `ImageUploadService` ที่เดียวกับทุกจุดที่รับภาพ
     */
    private function resolveSignature(User $user, ?string $base64): ?string
    {
        return $this->images->storeBase64($base64, 'signatures', 'signatures')
            ?? $user->profile?->signature_path;
    }
}
