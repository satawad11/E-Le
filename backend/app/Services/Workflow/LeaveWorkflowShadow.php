<?php

namespace App\Services\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\User;
use App\Models\UserDelegation;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowShadowComparison;
use Illuminate\Support\Facades\Log;

/**
 * LEGACY-05 · 05-3 — Shadow mode สำหรับสายอนุมัติใบลา
 *
 * ให้ engine ใหม่คำนวณคู่ขนานกับตรรกะเดิม แล้วบันทึกผลเทียบไว้
 * **ผลของตรรกะเดิมยังเป็น source of truth เสมอ** คลาสนี้ไม่มีทางเปลี่ยน
 * สถานะของใบลาใด ๆ และทุก method ถูกห่อ try/catch เพื่อไม่ให้ shadow ที่พังไป
 * ทำให้การอนุมัติจริงพังตาม
 *
 * สิ่งที่เทียบ (ตามที่เฟสนี้กำหนด):
 *   - current step
 *   - next approver
 *   - available transitions
 *
 * กฎข้อ 9: mismatch แม้แต่รายการเดียว = ห้าม cutover
 */
class LeaveWorkflowShadow
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly LeaveWorkflowProvisioner $provisioner,
    ) {}

    /**
     * เทียบทั้งสามจุดสำหรับใบลาหนึ่งใบ
     *
     * @return array<int,WorkflowShadowComparison>
     */
    public function compare(Leave $leave, ?User $actor = null): array
    {
        try {
            $instance = $this->provisioner->backfill($leave);

            if ($instance === null) {
                return [];
            }

            return array_values(array_filter([
                $this->compareCurrentStep($leave, $instance, $actor),
                $this->compareNextApprovers($leave, $instance, $actor),
                $this->compareTransitions($leave, $instance, $actor),
            ]));
        } catch (\Throwable $exception) {
            /*
             * shadow ต้องไม่ทำให้ระบบจริงล้ม — บันทึกไว้แล้วปล่อยผ่าน
             * ความล้มเหลวตรงนี้ถือเป็นเหตุ "ยังไม่พร้อม cutover" เช่นกัน
             */
            Log::warning('workflow shadow comparison ล้มเหลว', [
                'leave_id' => $leave->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * เตรียม shadow instance ให้สะท้อนสถานะ **ก่อน** ตรรกะเดิมจะลงมือ
     *
     * ต้องเรียกก่อนเข้า transaction ของตรรกะเดิมเสมอ มิฉะนั้น instance จะถูก
     * สร้างจากประวัติที่เดินไปแล้ว ทำให้ engine ถูกสั่งให้อนุมัติขั้นที่ผ่านไปแล้ว
     * — จุดนี้เคยทำให้ผลเทียบผิดพลาดตอนพัฒนา
     */
    public function prepare(Leave $leave): ?int
    {
        try {
            return $this->provisioner->backfill($leave)?->getKey();
        } catch (\Throwable $exception) {
            Log::warning('เตรียม shadow instance ไม่สำเร็จ', [
                'leave_id' => $leave->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * เดิน engine ตามการกระทำที่ตรรกะเดิม **ทำไปแล้ว** แล้วเทียบผลลัพธ์
     *
     * ต้องเรียกหลังจากตรรกะเดิมทำงานเสร็จและ commit แล้ว โดยส่ง instance ที่
     * `prepare()` เตรียมไว้ก่อนหน้าเข้ามา เพื่อให้ engine เริ่มจากสถานะเดียวกับ
     * ที่ตรรกะเดิมเริ่ม — นี่คือความหมายของการ "รันคู่ขนาน" ที่แท้จริง
     *
     * engine เดินบน shadow instance เท่านั้น — ไม่แตะใบลา ไม่แตะ `leave_approvals`
     *
     * @param  string  $action  approve | reject
     */
    public function mirrorAction(Leave $leave, string $action, User $actor, ?string $comment = null, ?int $instanceId = null): ?WorkflowShadowComparison
    {
        try {
            $instance = $instanceId === null
                ? ($this->provisioner->backfill($leave) ?? $this->engine->instanceFor($leave))
                : WorkflowInstance::find($instanceId);

            if ($instance === null) {
                return null;
            }

            $engineResult = ['status' => null, 'current_step_order' => null, 'error' => null];

            if ($instance->isRunning()) {
                try {
                    $outcome = $this->engine->perform($instance, $action, $actor, $comment, ['shadow' => true]);
                    $engineResult = [
                        'status' => $outcome['instance']->status,
                        'current_step_order' => $outcome['next_step']?->step_order,
                        'error' => null,
                    ];
                } catch (\Throwable $exception) {
                    // engine ปฏิเสธในสิ่งที่ของเดิมทำได้ = mismatch ที่ต้องรู้ ไม่ใช่ error ที่ซ่อน
                    $engineResult['error'] = $exception->getMessage();
                }
            } else {
                $engineResult['status'] = $instance->status;
            }

            $leave->refresh();

            $legacyResult = [
                'status' => $this->legacyInstanceStatus($leave),
                'current_step_order' => $this->legacyCurrentStepOrder($leave),
                'error' => null,
            ];

            $matched = $legacyResult['status'] === $engineResult['status']
                && $legacyResult['current_step_order'] === $engineResult['current_step_order']
                && $engineResult['error'] === null;

            return $this->record(
                $leave,
                $instance->refresh(),
                $action === 'reject'
                    ? WorkflowShadowComparison::CHECKPOINT_REJECT
                    : WorkflowShadowComparison::CHECKPOINT_APPROVE,
                $legacyResult,
                $engineResult,
                $matched,
                $actor,
            );
        } catch (\Throwable $exception) {
            Log::warning('workflow shadow mirror ล้มเหลว', [
                'leave_id' => $leave->getKey(),
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** สถานะใบลาแปลงเป็นคำศัพท์เดียวกับ instance เพื่อให้เทียบกันได้ */
    private function legacyInstanceStatus(Leave $leave): string
    {
        return match ($leave->status) {
            'Pending' => WorkflowInstance::STATUS_RUNNING,
            'Approved' => WorkflowInstance::STATUS_COMPLETED,
            'Rejected' => WorkflowInstance::STATUS_REJECTED,
            'Cancelled', 'Voided' => WorkflowInstance::STATUS_CANCELLED,
            default => WorkflowInstance::STATUS_RUNNING,
        };
    }

    // ------------------------------------------------------------ ตรรกะเดิม

    /**
     * ขั้นตอนปัจจุบันตามตรรกะเดิม
     *
     * ของเดิมอ่านจาก `leave_approvals` ที่ step_order = `leaves.approval_level`
     * และถ้าไม่มี snapshot จะสร้างจาก `approval_steps`
     * (`GovernmentApprovalController::step()`)
     */
    private function legacyCurrentStepOrder(Leave $leave): ?int
    {
        if (! in_array($leave->status, ['Pending'], true)) {
            return null;
        }

        $snapshot = $leave->approvals()->where('step_order', $leave->approval_level)->first();

        if ($snapshot !== null) {
            return (int) $snapshot->step_order;
        }

        $legacy = ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->firstWhere('step_order', $leave->approval_level);

        return $legacy === null ? null : (int) $legacy->step_order;
    }

    /**
     * ผู้ที่อนุมัติขั้นปัจจุบันได้ตามตรรกะเดิม
     *
     * ของเดิมยอมรับสามทาง: ถือ `leave.management.manage`, ถือบทบาทของขั้นตอน,
     * หรือรับมอบอำนาจจากผู้ที่ถือบทบาทนั้น
     *
     * ตรงนี้เทียบเฉพาะ "ผู้ถือบทบาทของขั้นตอน + ผู้รับมอบอำนาจ" ซึ่งเป็น
     * สายอนุมัติจริง ส่วนผู้ดูแลระบบเป็นทางลัดที่ข้ามสายอยู่แล้วทั้งสองฝั่ง
     *
     * @return array<int,int>
     */
    private function legacyApprovers(Leave $leave): array
    {
        $stepOrder = $this->legacyCurrentStepOrder($leave);

        if ($stepOrder === null) {
            return [];
        }

        $snapshot = $leave->approvals()->where('step_order', $stepOrder)->first();
        $roleId = $snapshot?->required_role_id
            ?? ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE)->firstWhere('step_order', $stepOrder)?->role_id;

        if ($roleId === null) {
            return [];
        }

        $holders = User::whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))->pluck('id')->all();

        $delegates = UserDelegation::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->whereHas('user.roles', fn ($query) => $query->where('roles.id', $roleId))
            ->pluck('delegate_id')
            ->all();

        $ids = array_map('intval', array_merge($holders, $delegates));
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * การกระทำที่ทำได้ตามตรรกะเดิม
     *
     * ของเดิมมีเพียง approve และ reject และทำได้เมื่อใบลายัง Pending เท่านั้น
     *
     * @return array<int,string>
     */
    private function legacyTransitions(Leave $leave): array
    {
        return $leave->status === 'Pending' && $this->legacyCurrentStepOrder($leave) !== null
            ? ['approve', 'reject']
            : [];
    }

    // ------------------------------------------------------------ การเทียบ

    private function compareCurrentStep(Leave $leave, WorkflowInstance $instance, ?User $actor): WorkflowShadowComparison
    {
        $legacy = $this->legacyCurrentStepOrder($leave);
        $engine = $this->engine->currentStep($instance)?->step_order;

        return $this->record(
            $leave,
            $instance,
            WorkflowShadowComparison::CHECKPOINT_CURRENT_STEP,
            ['step_order' => $legacy],
            ['step_order' => $engine === null ? null : (int) $engine],
            $legacy === ($engine === null ? null : (int) $engine),
            $actor,
        );
    }

    private function compareNextApprovers(Leave $leave, WorkflowInstance $instance, ?User $actor): WorkflowShadowComparison
    {
        $legacy = $this->legacyApprovers($leave);

        /*
         * engine คืนเฉพาะผู้ถือบทบาทโดยตรง ส่วนผู้รับมอบอำนาจตัดสินตอน authorize
         * จึงต้องรวมผู้รับมอบอำนาจเข้ามาก่อนเทียบ ให้ความหมายตรงกับฝั่งเดิม
         */
        $engine = $this->engine->nextApprovers($instance);
        $engine = array_merge($engine, $this->engineDelegates($instance, $engine));
        sort($engine);
        $engine = array_values(array_unique($engine));

        return $this->record(
            $leave,
            $instance,
            WorkflowShadowComparison::CHECKPOINT_NEXT_APPROVER,
            ['approver_ids' => $legacy],
            ['approver_ids' => $engine],
            $legacy === $engine,
            $actor,
        );
    }

    private function compareTransitions(Leave $leave, WorkflowInstance $instance, ?User $actor): WorkflowShadowComparison
    {
        $legacy = $this->legacyTransitions($leave);

        /*
         * engine ประกาศ return/cancel ไว้ในสายด้วย ซึ่งของเดิมไม่มี endpoint รองรับ
         * การเทียบจึงดูเฉพาะชุดที่ของเดิมรู้จัก (approve/reject) มิฉะนั้นจะนับ
         * ความสามารถใหม่เป็น mismatch ทั้งที่ยังไม่มีใครเรียกใช้
         */
        $engine = array_values(array_intersect($this->engine->availableTransitions($instance), ['approve', 'reject']));
        sort($engine);
        $legacySorted = $legacy;
        sort($legacySorted);

        return $this->record(
            $leave,
            $instance,
            WorkflowShadowComparison::CHECKPOINT_TRANSITIONS,
            ['actions' => $legacySorted],
            ['actions' => $engine],
            $legacySorted === $engine,
            $actor,
        );
    }

    /**
     * ผู้รับมอบอำนาจที่ทำแทนผู้ถือบทบาทของขั้นปัจจุบันได้
     *
     * @param  array<int,int>  $directCandidates
     * @return array<int,int>
     */
    private function engineDelegates(WorkflowInstance $instance, array $directCandidates): array
    {
        $task = $this->engine->currentTask($instance);

        if ($task === null) {
            return [];
        }

        $roleId = $task->assignee_role_id ?? $task->step?->assignee_role_id;

        if ($roleId === null) {
            return [];
        }

        return UserDelegation::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->whereHas('user.roles', fn ($query) => $query->where('roles.id', $roleId))
            ->pluck('delegate_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function record(
        Leave $leave,
        WorkflowInstance $instance,
        string $checkpoint,
        array $legacyResult,
        array $engineResult,
        bool $matched,
        ?User $actor,
    ): WorkflowShadowComparison {
        return WorkflowShadowComparison::create([
            'workflow_instance_id' => $instance->getKey(),
            'subject_type' => $leave->getMorphClass(),
            'subject_id' => $leave->getKey(),
            'checkpoint' => $checkpoint,
            'legacy_result' => $legacyResult,
            'engine_result' => $engineResult,
            'matched' => $matched,
            'mismatch_details' => $matched ? null : [
                'legacy' => $legacyResult,
                'engine' => $engineResult,
                'leave_status' => $leave->status,
                'approval_level' => $leave->approval_level,
            ],
            'actor_id' => $actor?->getKey(),
            'correlation_id' => request()?->attributes?->get('request_id'),
        ]);
    }
}
