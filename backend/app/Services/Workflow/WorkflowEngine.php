<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTask;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use App\Services\AuditLogger;
use App\Services\OrgScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-05 — Workflow Engine กลาง (CORE-WF-001)
 *
 * แทนตรรกะการเดินสายอนุมัติที่กระจายอยู่ใน controller ด้วยตัวเดียวที่:
 *   - อ่านสายอนุมัติจาก workflow version ที่ instance **pin ไว้**
 *   - บันทึกทุกการกระทำลง `workflow_actions` แบบ append-only
 *   - แยก "ผู้กด" ออกจาก "ผู้ที่ควรเป็นเจ้าของงาน" เพื่อรองรับผู้รักษาการ
 *
 * **สถานะปัจจุบัน: shadow mode** — engine คำนวณคู่ขนานกับตรรกะเดิมและบันทึก
 * ผลเทียบไว้ แต่ยังไม่ได้เป็นผู้ตัดสินสถานะจริงของใบลา (กฎข้อ 5 และ 7 ของเฟสนี้)
 * การ cutover เป็นขั้นตอนแยก (05-4) ที่ทำได้ต่อเมื่อผลเทียบตรง 100%
 */
class WorkflowEngine
{
    public function __construct(
        private readonly DelegationResolver $delegations,
        private readonly OrgScopeResolver $orgScope,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ อ่านสถานะ

    /**
     * instance ของเรื่องนี้ (ถ้ามี) — เรื่องหนึ่งมีได้หลาย instance ต่างเวอร์ชัน
     * จึงเอาตัวที่ยังรันอยู่ก่อน ไม่มีค่อยเอาตัวล่าสุด
     */
    public function instanceFor(Model $subject): ?WorkflowInstance
    {
        return WorkflowInstance::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByRaw("CASE WHEN status = '".WorkflowInstance::STATUS_RUNNING."' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }

    /**
     * งานที่รออยู่ในลำดับถัดไป — ตัวกำหนดว่า "ตอนนี้อยู่ขั้นไหน"
     *
     * instance ที่จบแล้ว (อนุมัติ/ปฏิเสธ/ยกเลิก) ไม่มีขั้นปัจจุบัน แม้จะยังมี
     * task ค้างสถานะ pending อยู่ก็ตาม — กรณีนี้เกิดกับข้อมูลที่ backfill มาจาก
     * ใบลาที่ถูกยกเลิกทั้งที่ยังไม่มีใครอนุมัติสักขั้น
     *
     * shadow comparison จับความต่างข้อนี้ได้จากข้อมูลจริง (leave#3) — ของเดิม
     * ตอบว่า "ไม่มีขั้นปัจจุบัน" เพราะดูสถานะใบลาก่อน ส่วน engine เดิมตอบขั้นที่ 1
     */
    public function currentTask(WorkflowInstance $instance): ?WorkflowTask
    {
        if (! $instance->isRunning()) {
            return null;
        }

        return $instance->tasks()
            ->where('status', WorkflowTask::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();
    }

    public function currentStep(WorkflowInstance $instance): ?WorkflowStep
    {
        return $this->currentTask($instance)?->step;
    }

    /**
     * ผู้ที่มีสิทธิ์ทำงานขั้นปัจจุบัน
     *
     * คืนเป็น id ของผู้ใช้ เรียงแล้ว เพื่อให้เทียบกับตรรกะเดิมได้ตรง ๆ
     *
     * @return array<int,int>
     */
    public function nextApprovers(WorkflowInstance $instance): array
    {
        $task = $this->currentTask($instance);

        if ($task === null) {
            return [];
        }

        return $this->candidatesFor($instance, $task);
    }

    /**
     * การกระทำที่ผู้ใช้คนนี้ทำได้กับ instance นี้ ณ ตอนนี้
     *
     * @return array<int,string>
     */
    public function availableTransitions(WorkflowInstance $instance, ?User $user = null): array
    {
        if (! $instance->isRunning()) {
            return [];
        }

        $task = $this->currentTask($instance);

        if ($task === null) {
            return [];
        }

        $actions = WorkflowTransition::query()
            ->where('workflow_version_id', $instance->workflow_version_id)
            ->where('from_step_id', $task->workflow_step_id)
            ->orderBy('sort_order')
            ->pluck('action')
            ->unique()
            ->values()
            ->all();

        if ($user === null) {
            return $actions;
        }

        return $this->authorize($instance, $user)['allowed']
            ? $actions
            : [];
    }

    /**
     * ผู้ใช้คนนี้ทำงานขั้นปัจจุบันได้หรือไม่ และทำในนามใคร
     *
     * ให้ผลตรงกับ `GovernmentApprovalController::authorizeStep()` ทุกกรณี:
     *   1. ถือสิทธิ์ `leave.management.manage` → ผ่าน โดยไม่นับเป็นการรับมอบอำนาจ
     *   2. ถือบทบาทที่ขั้นตอนต้องการ → ผ่านในนามตนเอง
     *   3. รับมอบอำนาจจากผู้ที่ถือบทบาทนั้น → ผ่านในนามผู้มอบ
     *
     * @return array{allowed:bool, effective_actor_id:?int, delegated_by_id:?int, reason:?string}
     */
    public function authorize(WorkflowInstance $instance, User $user, ?string $on = null): array
    {
        $deny = fn (string $reason) => ['allowed' => false, 'effective_actor_id' => null, 'delegated_by_id' => null, 'reason' => $reason];

        if (! $instance->isRunning()) {
            return $deny('instance_not_running');
        }

        $task = $this->currentTask($instance);

        if ($task === null) {
            return $deny('no_pending_task');
        }

        $step = $task->step;

        // ผู้ดูแลสายอนุมัติข้ามลำดับได้ตามพฤติกรรมเดิมของระบบ
        if ($user->can('leave.management.manage')) {
            return ['allowed' => true, 'effective_actor_id' => $user->getKey(), 'delegated_by_id' => null, 'reason' => 'workflow_manager'];
        }

        if ($this->isDirectAssignee($instance, $task, $step, $user)) {
            return ['allowed' => true, 'effective_actor_id' => $user->getKey(), 'delegated_by_id' => null, 'reason' => 'assignee'];
        }

        $delegator = $this->delegations->delegatorFor(
            $user,
            $task->assignee_role_id ?? $step?->assignee_role_id,
            $on,
            $instance->version?->workflow_definition_id,
        );

        if ($delegator !== null) {
            return ['allowed' => true, 'effective_actor_id' => $delegator, 'delegated_by_id' => $delegator, 'reason' => 'delegated'];
        }

        return $deny('not_assignee');
    }

    // ------------------------------------------------------------------ เดินสาย

    /**
     * เริ่ม instance ใหม่ให้เรื่องหนึ่ง โดย pin เวอร์ชันที่มีผล ณ วันนี้
     *
     * ถ้ามี instance ของเรื่องนี้บนเวอร์ชันนั้นอยู่แล้วจะคืนตัวเดิม (idempotent)
     * เพื่อให้ backfill รันซ้ำได้อย่างปลอดภัย
     */
    public function start(WorkflowDefinition $definition, Model $subject, ?int $initiatedBy = null, bool $shadow = true, array $meta = []): WorkflowInstance
    {
        $version = $definition->activeVersion();

        if ($version === null) {
            throw new \RuntimeException("workflow `{$definition->code}` ยังไม่มีเวอร์ชันที่มีผลบังคับใช้");
        }

        return $this->startOnVersion($version, $subject, $initiatedBy, $shadow, $meta);
    }

    public function startOnVersion(WorkflowVersion $version, Model $subject, ?int $initiatedBy = null, bool $shadow = true, array $meta = []): WorkflowInstance
    {
        $existing = WorkflowInstance::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('workflow_version_id', $version->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($version, $subject, $initiatedBy, $shadow, $meta) {
            $steps = $version->steps()->get();

            if ($steps->isEmpty()) {
                throw new \RuntimeException("workflow version {$version->getKey()} ไม่มีขั้นตอน");
            }

            $instance = WorkflowInstance::create([
                'workflow_version_id' => $version->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => WorkflowInstance::STATUS_RUNNING,
                'current_step_id' => $steps->first()->getKey(),
                'initiated_by' => $initiatedBy,
                'is_shadow' => $shadow,
                'correlation_id' => request()?->attributes?->get('request_id'),
                'meta' => $meta === [] ? null : $meta,
                'started_at' => now(),
            ]);

            foreach ($steps as $index => $step) {
                WorkflowTask::create([
                    'workflow_instance_id' => $instance->getKey(),
                    'workflow_step_id' => $step->getKey(),
                    'status' => WorkflowTask::STATUS_PENDING,
                    'sequence' => $index,
                    'assignee_role_id' => $step->assignee_role_id,
                    'assignee_user_id' => $step->assignee_user_id,
                    'due_at' => $step->sla_hours ? now()->addHours($step->sla_hours) : null,
                ]);
            }

            $this->record($instance, null, 'started', null, null, $steps->first(), null);

            return $instance;
        }, 5);
    }

    /**
     * ลงมือทำขั้นปัจจุบัน
     *
     * @param  string  $action  approve | reject | return | cancel
     * @return array{instance:WorkflowInstance, task:WorkflowTask, next_step:?WorkflowStep, final:bool}
     */
    public function perform(WorkflowInstance $instance, string $action, User $actor, ?string $comment = null, array $context = []): array
    {
        return DB::transaction(function () use ($instance, $action, $actor, $comment, $context) {
            /*
             * ล็อกแถวก่อนอ่านสถานะ เพื่อไม่ให้ผู้อนุมัติสองคนกดพร้อมกันแล้ว
             * เดินสายซ้ำสองครั้ง (duplicate action / concurrent approval)
             */
            $locked = WorkflowInstance::whereKey($instance->getKey())->lockForUpdate()->firstOrFail();

            $decision = $this->authorize($locked, $actor);

            if (! $decision['allowed']) {
                throw new WorkflowAuthorizationException($decision['reason'] ?? 'forbidden');
            }

            $task = $this->currentTask($locked);
            $step = $task->step;

            $transition = WorkflowTransition::query()
                ->where('workflow_version_id', $locked->workflow_version_id)
                ->where('from_step_id', $step->getKey())
                ->where('action', $action)
                ->orderBy('sort_order')
                ->first();

            if ($transition === null) {
                throw new WorkflowTransitionException("ขั้นตอน `{$step->code}` ไม่รองรับการกระทำ `{$action}`");
            }

            if ($transition->requires_comment && blank($comment)) {
                throw new WorkflowTransitionException('การกระทำนี้ต้องระบุเหตุผล');
            }

            $task->update([
                'status' => $this->taskStatusFor($action),
                'completed_by' => $actor->getKey(),
                'effective_actor_id' => $decision['effective_actor_id'],
                'delegated_by_id' => $decision['delegated_by_id'],
                'completed_at' => now(),
                'comment' => $comment,
            ]);

            $nextStep = $transition->toStep;
            $final = false;

            if ($nextStep === null) {
                /*
                 * ไม่มีขั้นถัดไปที่ระบุไว้ = จบสาย
                 * `approve` ที่ไม่ใช่ขั้นสุดท้ายจะไปต่อที่ task ถัดไปตามลำดับ
                 */
                $nextTask = $locked->tasks()->where('status', WorkflowTask::STATUS_PENDING)->orderBy('sequence')->first();

                if ($action === WorkflowTransition::APPROVE && $nextTask !== null) {
                    $nextStep = $nextTask->step;
                } else {
                    $final = true;
                }
            }

            if ($final) {
                // ขั้นที่ยังค้างอยู่ต้องปิด ไม่ปล่อยให้ค้างเป็น pending ตลอดไป
                $locked->tasks()
                    ->where('status', WorkflowTask::STATUS_PENDING)
                    ->update(['status' => WorkflowTask::STATUS_SKIPPED, 'updated_at' => now()]);

                $locked->update([
                    'status' => $transition->result ?? $this->instanceResultFor($action),
                    'current_step_id' => null,
                    'completed_at' => now(),
                ]);
            } else {
                $this->openTaskFor($locked, $nextStep);
                $locked->update(['current_step_id' => $nextStep->getKey()]);
            }

            $this->record($locked, $task, $action, $decision, $step, $nextStep, $comment, $context);

            return ['instance' => $locked->refresh(), 'task' => $task->refresh(), 'next_step' => $nextStep, 'final' => $final];
        }, 5);
    }

    // ------------------------------------------------------------------ ภายใน

    /**
     * ผู้ที่เข้าเงื่อนไขเป็นเจ้าของงานขั้นนี้โดยตรง (ยังไม่รวมผู้รับมอบอำนาจ)
     *
     * @return array<int,int>
     */
    private function candidatesFor(WorkflowInstance $instance, WorkflowTask $task): array
    {
        $step = $task->step;
        $roleId = $task->assignee_role_id ?? $step?->assignee_role_id;

        $ids = match ($step?->assignee_type) {
            WorkflowStep::ASSIGNEE_USER => array_filter([$task->assignee_user_id ?? $step->assignee_user_id]),
            WorkflowStep::ASSIGNEE_PERMISSION => $step->assignee_permission
                ? User::permission($step->assignee_permission)->pluck('id')->all()
                : [],
            WorkflowStep::ASSIGNEE_INITIATOR => array_filter([$instance->initiated_by]),
            WorkflowStep::ASSIGNEE_ORG_MANAGER => $this->orgManagersFor($instance),
            default => $roleId === null
                ? []
                : User::whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))->pluck('id')->all(),
        };

        $ids = array_map('intval', $ids);
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * หัวหน้าหน่วยงานของผู้ยื่น — ใช้ `org_unit_managers` ที่ LEGACY-04 สร้างไว้
     * ตาม ADR-006 ที่ห้ามผูกตำแหน่งหัวหน้ากับชื่อ role
     *
     * @return array<int,int>
     */
    private function orgManagersFor(WorkflowInstance $instance): array
    {
        $initiator = $instance->initiated_by ? User::find($instance->initiated_by) : null;

        if ($initiator === null) {
            return [];
        }

        $homeUnit = $this->orgScope->homeOrgUnitId($initiator);

        if ($homeUnit === null || ! Schema::hasTable('org_unit_managers')) {
            return [];
        }

        $today = now()->toDateString();

        return DB::table('org_unit_managers')
            ->where('org_unit_id', $homeUnit)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function isDirectAssignee(WorkflowInstance $instance, WorkflowTask $task, ?WorkflowStep $step, User $user): bool
    {
        return in_array((int) $user->getKey(), $this->candidatesFor($instance, $task), true);
    }

    /**
     * เปิดงานของขั้นที่กำลังจะไปให้กลับมารอทำอีกครั้ง
     *
     * จำเป็นตอน `return` (ส่งกลับแก้ไข) เพราะขั้นก่อนหน้าถูกปิดไปแล้วตอนอนุมัติ
     * ถ้าไม่เปิดใหม่ `currentTask()` จะข้ามไปหยิบขั้นที่ยัง pending ตัวถัดไป
     * ซึ่งอยู่ **หลัง** ขั้นที่เพิ่งส่งกลับ — กลายเป็นส่งกลับแล้วเดินหน้าแทน
     *
     * ประวัติไม่หาย: การกระทำทุกครั้งถูกบันทึกไว้ใน `workflow_actions` แล้ว
     * ซึ่งเป็น append-only
     */
    private function openTaskFor(WorkflowInstance $instance, WorkflowStep $step): void
    {
        $task = $instance->tasks()->where('workflow_step_id', $step->getKey())->first();

        if ($task === null || $task->isPending()) {
            return;
        }

        $task->update([
            'status' => WorkflowTask::STATUS_PENDING,
            'completed_by' => null,
            'effective_actor_id' => null,
            'delegated_by_id' => null,
            'completed_at' => null,
            'comment' => null,
        ]);
    }

    private function taskStatusFor(string $action): string
    {
        return match ($action) {
            WorkflowTransition::APPROVE => WorkflowTask::STATUS_APPROVED,
            WorkflowTransition::REJECT => WorkflowTask::STATUS_REJECTED,
            WorkflowTransition::RETURN => WorkflowTask::STATUS_RETURNED,
            WorkflowTransition::CANCEL => WorkflowTask::STATUS_CANCELLED,
            default => WorkflowTask::STATUS_APPROVED,
        };
    }

    private function instanceResultFor(string $action): string
    {
        return match ($action) {
            WorkflowTransition::REJECT => WorkflowInstance::STATUS_REJECTED,
            WorkflowTransition::RETURN => WorkflowInstance::STATUS_RETURNED,
            WorkflowTransition::CANCEL => WorkflowInstance::STATUS_CANCELLED,
            default => WorkflowInstance::STATUS_COMPLETED,
        };
    }

    /**
     * บันทึกลง `workflow_actions` และ central audit log
     *
     * ครบตามที่เฟสนี้กำหนด: actor, effective actor, workflow, instance, action,
     * from step, to step, reason, timestamp, correlation id
     */
    private function record(
        WorkflowInstance $instance,
        ?WorkflowTask $task,
        string $action,
        ?array $decision,
        ?WorkflowStep $from,
        ?WorkflowStep $to,
        ?string $comment,
        array $context = [],
    ): void {
        $request = request();
        $correlationId = $request?->attributes?->get('request_id');

        WorkflowAction::create([
            'workflow_instance_id' => $instance->getKey(),
            'workflow_task_id' => $task?->getKey(),
            'action' => $action,
            'actor_id' => $decision === null ? null : ($request?->user()?->getKey()),
            'effective_actor_id' => $decision['effective_actor_id'] ?? null,
            'delegated_by_id' => $decision['delegated_by_id'] ?? null,
            'from_step_id' => $from?->getKey(),
            'to_step_id' => $to?->getKey(),
            'comment' => $comment,
            'correlation_id' => $correlationId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'context' => $context === [] ? null : $context,
        ]);

        $this->audit->log(
            'workflow.'.$action,
            'workflow_instance',
            $instance->getKey(),
            null,
            [
                'subject_type' => $instance->subject_type,
                'subject_id' => $instance->subject_id,
                'workflow_version_id' => $instance->workflow_version_id,
                'from_step' => $from?->code,
                'to_step' => $to?->code,
                'effective_actor_id' => $decision['effective_actor_id'] ?? null,
                'delegated_by_id' => $decision['delegated_by_id'] ?? null,
                'is_shadow' => $instance->is_shadow,
            ],
        );
    }
}
