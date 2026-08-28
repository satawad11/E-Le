<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;

/**
 * ผู้ตัดสินว่า "ใครอนุมัติขั้นไหนได้ และอนุมัติแล้วไปต่อที่ไหน" ของใบลา
 *
 * CLEANUP-01 / CLEANUP-02 (D4) — รวมจุดตัดสินใจของสองระบบไว้ที่เดียว
 *
 * ก่อนหน้านี้ตรรกะเดินสายอยู่สองที่: controller ของเดิม กับ `WorkflowEngine`
 * ที่เดินแบบ shadow การเปลี่ยนกฎอนุมัติจึงต้องแก้สองที่ให้ตรงกันเอง
 * คลาสนี้ทำให้เหลือทางเข้าเดียว และการ cutover กลายเป็นการสลับ "ใครเป็นคนตัดสิน"
 * ไม่ใช่การเขียนสายอนุมัติใหม่ทั้งชุด
 *
 * ครอบทั้งสองสายของใบลา:
 *   - `STAGE_REQUEST`      สายขอลา
 *   - `STAGE_CANCELLATION` สายขอยกเลิกใบลา (เพิ่มใน CLEANUP-02 — เอกสารปิดเฟส
 *                          LEGACY-05 ระบุว่าต้องรวมเข้า engine ก่อน cutover)
 *
 * **การเขียนข้อมูลยังเหมือนเดิมทุกกรณี** — `leave_approvals`, `leaves.approval_level`,
 * `leaves.cancellation_approval_level`, สถานะใบลา และ audit log ถูกเขียนโดยผู้เรียกเสมอ
 * ไม่ว่าผู้ตัดสินจะเป็นใคร (dual-write ตามข้อกำหนดข้อ 3 ของ LEGACY-05 · 05-4)
 *
 * สลับผู้ตัดสินด้วย `config('workflow.engine_is_source_of_truth')` — ค่าเริ่มต้นคือ
 * ตรรกะเดิม การเปิดใช้ engine ต้องมีหลักฐาน shadow ที่ตรงกัน 100% จากการอนุมัติจริง
 * ตามที่ `docs/checkpoints/LEGACY-05-REPORT.md` ข้อ 11 กำหนดไว้
 */
class LeaveApprovalCoordinator
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly LeaveWorkflowProvisioner $requestChain,
        private readonly LeaveCancellationWorkflowProvisioner $cancellationChain,
        private readonly ApproverResolverService $resolver,
    ) {}

    public function engineIsSourceOfTruth(): bool
    {
        return (bool) config('workflow.engine_is_source_of_truth', false);
    }

    /**
     * ผู้ใช้คนนี้ทำรายการในขั้นปัจจุบันของสายนี้ได้หรือไม่
     *
     * ใช้โดยรายการงานที่ต้องอนุมัติ เพื่อให้สิ่งที่เห็นในรายการกับสิ่งที่กดได้จริง
     * ตัดสินด้วยกติกาชุดเดียวกันเสมอ ไม่ว่าผู้ตัดสินจะเป็น engine หรือของเดิม
     *
     * `$step` ส่งมาเมื่อผู้เรียกรู้ขั้นอยู่แล้ว (เช่นใบลาเก่าที่ยังไม่มี snapshot)
     */
    public function canAct(
        Leave $leave,
        User $actor,
        LeaveApproval|ApprovalStep|null $step = null,
        string $stage = LeaveApproval::STAGE_REQUEST,
    ): bool {
        if ($actor->can('leave.management.manage')) {
            return true;
        }

        if ($this->engineIsSourceOfTruth() && $step === null) {
            $instance = $this->engine->instanceFor($leave, $this->provisionerFor($stage)::DEFINITION_CODE);

            if ($instance !== null) {
                return $this->engine->authorize($instance, $actor)['allowed'];
            }
        }

        $step ??= $this->snapshotFor($leave, $stage)->firstWhere('step_order', $this->levelFor($leave, $stage));

        return $step !== null
            && $leave->user !== null
            && $this->resolver->isAuthorizedApprover($actor, $leave->user, $step);
    }

    /**
     * ตัดสินการกระทำหนึ่งครั้งบนใบลาที่ล็อกแถวไว้แล้ว
     *
     * @param  string  $action  approve | reject
     * @return array{step: LeaveApproval, delegated_from: ?int, final: bool, next_step_order: ?int}
     */
    public function decide(
        Leave $locked,
        string $action,
        User $actor,
        ?string $comment,
        string $stage = LeaveApproval::STAGE_REQUEST,
    ): array {
        return $this->engineIsSourceOfTruth()
            ? $this->decideWithEngine($locked, $action, $actor, $comment, $stage)
            : $this->decideWithLegacyChain($locked, $action, $actor, $stage);
    }

    /**
     * เส้นทางเดิม — แถวสรุปใน `leave_approvals` และคอลัมน์ระดับของใบลาเป็นผู้ตัดสิน
     *
     * @return array{step: LeaveApproval, delegated_from: ?int, final: bool, next_step_order: ?int}
     */
    private function decideWithLegacyChain(Leave $locked, string $action, User $actor, string $stage): array
    {
        $step = $this->stepFor($locked, $stage);
        $delegatedFrom = $this->authorizeLegacy($actor, $step, $locked);

        $next = $action === 'approve'
            ? $this->snapshotQuery($locked, $stage)->where('step_order', '>', $step->step_order)->orderBy('step_order')->first()
            : null;

        return [
            'step' => $step,
            'delegated_from' => $delegatedFrom,
            'final' => $next === null,
            'next_step_order' => $next?->step_order,
        ];
    }

    /**
     * เส้นทางใหม่ — Workflow Engine เป็นผู้ตัดสินทั้งสิทธิ์และขั้นถัดไป
     *
     * engine ทำงานบน instance ของสายนั้นโดยเฉพาะ (สร้างจากแถวสรุปถ้ายังไม่มี)
     * ผลที่ได้ถูกแปลงกลับเป็นแถว `leave_approvals` เพื่อให้ผู้เรียกเขียนของเดิมต่อได้ครบ
     *
     * @return array{step: LeaveApproval, delegated_from: ?int, final: bool, next_step_order: ?int}
     */
    private function decideWithEngine(Leave $locked, string $action, User $actor, ?string $comment, string $stage): array
    {
        $instance = $this->authoritativeInstance($locked, $stage);

        abort_if(
            $instance === null,
            422,
            'ยังไม่ได้ตั้งค่าสายการอนุมัติในระบบ กรุณาตั้งค่าที่เมนูจัดการลำดับการอนุมัติก่อน',
        );

        $stepOrderBefore = $this->engine->currentStep($instance)?->step_order;

        abort_if($stepOrderBefore === null, 422, 'ใบลานี้ไม่มีขั้นตอนที่รออนุมัติอยู่');

        try {
            $outcome = $this->engine->perform($instance, $action, $actor, $comment);
        } catch (WorkflowAuthorizationException) {
            abort(403, 'ไม่มีสิทธิ์อนุมัติในขั้นตอนนี้');
        } catch (WorkflowTransitionException $exception) {
            abort(422, $exception->getMessage());
        }

        /*
         * แถวสรุปของขั้นนี้ต้องมีอยู่เสมอ — instance ถูกสร้างจาก `leave_approvals`
         * ถ้าหาไม่เจอแปลว่าสายอนุมัติถูกแก้ระหว่างที่ใบลานี้เดินอยู่ จึงถอยไปสร้าง
         * แถวย้อนหลังด้วยวิธีเดียวกับเส้นทางเดิม
         */
        $step = $this->snapshotQuery($locked, $stage)->where('step_order', $stepOrderBefore)->first()
            ?? $this->stepFor($locked, $stage, $stepOrderBefore);

        return [
            'step' => $step,
            'delegated_from' => $outcome['task']->delegated_by_id,
            'final' => $outcome['final'],
            'next_step_order' => $outcome['next_step']?->step_order,
        ];
    }

    /** instance ที่ใช้ตัดสินจริงของสายนี้ — ไม่ใช่ shadow */
    private function authoritativeInstance(Leave $leave, string $stage): ?WorkflowInstance
    {
        $provisioner = $this->provisionerFor($stage);

        /*
         * CLEANUP-02 — ออกเวอร์ชันจากสายอนุมัติปัจจุบันให้เองถ้ายังไม่มี
         *
         * ระบบที่ไม่เคยรัน `LeaveWorkflowSeeder` จะไม่มีเวอร์ชันที่ใช้งานอยู่เลย
         * ถ้าเปิดสวิตช์ cutover ในสภาพนั้น การอนุมัติทุกใบจะล้มทันที
         * `publishCurrentVersion()` เป็น idempotent — เรียกซ้ำไม่ออกเวอร์ชันใหม่
         */
        if ($provisioner->definition()->activeVersion() === null) {
            try {
                $provisioner->publishCurrentVersion();
            } catch (\RuntimeException) {
                // ยังไม่ได้ตั้งค่าสายอนุมัติเลย — ปล่อยให้ผู้เรียกแจ้งข้อความมาตรฐานด้านล่าง
                return null;
            }
        }

        $instance = $provisioner->backfill($leave, shadow: false);

        /*
         * ใบลาที่เคยถูกเดิน shadow มาก่อนจะมี instance ที่ `is_shadow = true` อยู่แล้ว
         * เมื่อ cutover แล้ว instance เดิมกลายเป็นตัวจริง — ปลดธง shadow ทิ้ง
         * เพื่อไม่ให้รายงานเทียบผลนับมันเป็นการทดลองต่อไป
         */
        if ($instance !== null && $instance->is_shadow) {
            $instance->forceFill(['is_shadow' => false])->save();
        }

        return $instance;
    }

    private function provisionerFor(string $stage): LeaveWorkflowProvisioner
    {
        return $stage === LeaveApproval::STAGE_CANCELLATION
            ? $this->cancellationChain
            : $this->requestChain;
    }

    private function legacyChainFor(string $stage): string
    {
        return $stage === LeaveApproval::STAGE_CANCELLATION
            ? ApprovalStep::CHAIN_CANCELLATION
            : ApprovalStep::CHAIN_LEAVE;
    }

    private function levelFor(Leave $leave, string $stage): ?int
    {
        return $stage === LeaveApproval::STAGE_CANCELLATION
            ? ($leave->cancellation_approval_level ?: $leave->approval_level)
            : $leave->approval_level;
    }

    private function snapshotQuery(Leave $leave, string $stage)
    {
        return $leave->allApprovals()->where('stage', $stage);
    }

    private function snapshotFor(Leave $leave, string $stage)
    {
        return $this->snapshotQuery($leave, $stage)->get();
    }

    /**
     * แถวสรุปขั้นอนุมัติของใบลา — สร้างย้อนหลังจากสายปัจจุบันเมื่อใบลาเก่ายังไม่มี
     */
    private function stepFor(Leave $leave, string $stage, ?int $stepOrder = null): LeaveApproval
    {
        $stepOrder ??= $this->levelFor($leave, $stage);

        $snapshot = $this->snapshotQuery($leave, $stage)->where('step_order', $stepOrder)->first();

        if ($snapshot) {
            return $snapshot;
        }

        $chain = $this->legacyChainFor($stage);
        $legacy = ApprovalStep::stepsFor($chain)->firstWhere('step_order', $stepOrder);

        abort_unless(
            $legacy,
            422,
            ApprovalStep::query()->chain($chain)->exists()
                ? 'ไม่พบขั้นตอนอนุมัติลำดับที่ '.$stepOrder.' กรุณาตรวจสอบการตั้งค่าลำดับการอนุมัติ'
                : 'ยังไม่ได้ตั้งค่าลำดับการอนุมัติในระบบ กรุณาตั้งค่าที่เมนูจัดการลำดับการอนุมัติก่อน',
        );

        return LeaveApproval::firstOrCreate(
            ['leave_id' => $leave->id, 'stage' => $stage, 'step_order' => $legacy->step_order],
            [
                'step_name' => $legacy->name,
                'required_role_id' => $legacy->role_id,
                'status' => 'Pending',
                'approver_type' => $legacy->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                'specific_user_id' => $legacy->specific_user_id,
                'permission_name' => $legacy->permission_name,
            ],
        );
    }

    /** สิทธิ์อนุมัติของเส้นทางเดิม — ผู้ดูแลสายอนุมัติข้ามลำดับได้ตามพฤติกรรมเดิม */
    private function authorizeLegacy(User $actor, LeaveApproval $step, Leave $leave): ?int
    {
        if ($actor->can('leave.management.manage') || $actor->roles->contains('name', 'Super Admin')) {
            return null;
        }

        abort_unless(
            $this->resolver->isAuthorizedApprover($actor, $leave->user, $step),
            403,
            'ไม่มีสิทธิ์อนุมัติในขั้นตอนนี้',
        );

        return $this->resolver->getDelegatedFromId($actor, $leave->user, $step);
    }
}
