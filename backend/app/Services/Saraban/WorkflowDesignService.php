<?php

namespace App\Services\Saraban;

use App\Models\User;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use App\Services\AuditLogger;
use App\Services\Modules\ModuleRegistry;
use App\Services\TrustedClock;
use Illuminate\Support\Facades\DB;

/**
 * ออกแบบสายการเดินหนังสือของงานสารบรรณ (SoT §11, §12 · RISK-26)
 *
 * **ที่มา:** สายการเดินหนังสือแก้ได้เฉพาะด้วย migration เท่านั้นมาตลอด ผู้ดูแล
 * สถานศึกษาจึงเพิ่มขั้น เช่น "รองผู้อำนวยการพิจารณา" เองไม่ได้ ทั้งที่ผังการเสนอ
 * หนังสือต่างกันไปในแต่ละแห่ง
 *
 * ข้อบังคับที่คลาสนี้รักษา:
 *
 *   1. **แก้ได้เฉพาะฉบับร่าง** — เวอร์ชันที่ใช้งานอยู่และที่ปลดแล้วเป็นหลักฐาน
 *      ว่าเรื่องในอดีตเดินตามกติกาใด แก้ย้อนหลังไม่ได้เด็ดขาด
 *   2. **เรื่องที่กำลังเดินไม่ถูกกระทบ** — instance pin เวอร์ชันของตัวเองไว้
 *      การเผยแพร่เวอร์ชันใหม่จึงมีผลกับเรื่องที่เริ่มหลังจากนั้นเท่านั้น
 *   3. **ผู้ใช้จัดลำดับขั้น ระบบผูกเส้นทางให้** — ไม่เปิดให้แก้ transition ด้วยมือ
 *      เพราะสายงานที่ผูกเส้นทางผิดจะกลายเป็นเรื่องที่เดินต่อไม่ได้และค้างถาวร
 *      โดยไม่มีข้อความใดบอก · ผู้ดูแลตอบได้แค่ "ใครทำขั้นไหน" ส่วนเส้นทางเป็น
 *      ผลลัพธ์ของลำดับนั้นเสมอ
 *   4. **ผูกกับสิทธิ์ ไม่ผูกกับคน** (SoT §11) — และเฉพาะสิทธิ์ในสารบัญของสารบรรณ
 *      เท่านั้น สายงานของสารบรรณจึงอ้างสิทธิ์ของโมดูลการลาไม่ได้
 */
class WorkflowDesignService
{
    /** จำนวนขั้นสูงสุด — มากกว่านี้คือผังที่คนอ่านไม่ออกและตามเรื่องไม่ทัน */
    public const MAX_STEPS = 12;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    public function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::query()
            ->where('code', DocumentWorkflowService::DEFINITION_CODE)
            ->firstOrFail();
    }

    /**
     * ภาพรวมทุกเวอร์ชันของสายงาน
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $definition = $this->definition();

        return [
            'definition' => $definition->only(['id', 'code', 'name', 'description']),
            'versions' => $definition->versions()
                ->withCount('steps')
                ->orderByDesc('version')
                ->get()
                ->map(fn (WorkflowVersion $version) => [
                    ...$version->only(['id', 'version', 'status', 'notes']),
                    'effective_from' => $version->effective_from?->toDateString(),
                    'effective_to' => $version->effective_to?->toDateString(),
                    'published_at' => $version->published_at?->toIso8601String(),
                    'steps_count' => $version->steps_count,
                    // เวอร์ชันที่ยังมีเรื่องเดินอยู่ ปลดทิ้งไม่ได้
                    'has_running_instances' => $version->hasRunningInstances(),
                    'editable' => $version->status === WorkflowVersion::STATUS_DRAFT,
                ])
                ->all(),
        ];
    }

    /**
     * รายละเอียดของเวอร์ชันหนึ่ง — ขั้นตอนและเส้นทางที่ระบบผูกให้
     *
     * @return array<string,mixed>
     */
    public function detail(WorkflowVersion $version): array
    {
        return [
            ...$version->only(['id', 'version', 'status', 'notes']),
            'effective_from' => $version->effective_from?->toDateString(),
            'editable' => $version->status === WorkflowVersion::STATUS_DRAFT,
            'has_running_instances' => $version->hasRunningInstances(),
            'steps' => $version->steps()->get()->map(fn (WorkflowStep $step) => [
                ...$step->only(['id', 'code', 'name', 'step_order', 'assignee_permission', 'is_final']),
            ])->all(),
            'transitions' => $version->transitions()
                ->with(['fromStep:id,name', 'toStep:id,name'])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (WorkflowTransition $transition) => [
                    'action' => $transition->action,
                    'name' => $transition->name,
                    'from' => $transition->fromStep?->name,
                    'to' => $transition->toStep?->name,
                    'result' => $transition->result,
                    'requires_comment' => (bool) $transition->requires_comment,
                ])
                ->all(),
        ];
    }

    /**
     * สร้างฉบับร่างจากเวอร์ชันที่ใช้งานอยู่
     *
     * **คัดลอกของเดิมมาให้ ไม่เริ่มจากหน้าว่าง** — ผู้ดูแลส่วนใหญ่ต้องการเพิ่ม
     * หรือถอดขั้นเดียว การให้กรอกใหม่ทั้งผังคือทางที่พิมพ์ตกหล่นแล้วสายงานพัง
     */
    public function createDraft(User $actor): WorkflowVersion
    {
        $definition = $this->definition();

        $existingDraft = $definition->versions()
            ->where('status', WorkflowVersion::STATUS_DRAFT)
            ->first();

        if ($existingDraft !== null) {
            throw new WorkflowDesignException('มีฉบับร่างค้างอยู่แล้ว — แก้ไขหรือลบฉบับร่างเดิมก่อน');
        }

        $source = $definition->activeVersion();

        return DB::transaction(function () use ($definition, $source, $actor) {
            $draft = WorkflowVersion::create([
                'workflow_definition_id' => $definition->id,
                'version' => (int) $definition->versions()->max('version') + 1,
                'status' => WorkflowVersion::STATUS_DRAFT,
                'effective_from' => $this->clock->now()->toDateString(),
                'notes' => $source === null ? 'ฉบับร่างใหม่' : "คัดลอกจากเวอร์ชันที่ {$source->version}",
            ]);

            $steps = $source === null ? [] : $source->steps()->get()->map(fn (WorkflowStep $step) => [
                'code' => $step->code,
                'name' => $step->name,
                'assignee_permission' => $step->assignee_permission,
            ])->all();

            if ($steps !== []) {
                $this->replaceSteps($draft, $steps);
            }

            $this->audit->log('saraban.workflow.draft_created', $draft, $draft->id, null, [
                'version' => $draft->version,
                'copied_from' => $source?->version,
            ], null, $actor->id);

            return $draft->fresh();
        }, 5);
    }

    /**
     * บันทึกลำดับขั้นของฉบับร่าง แล้วผูกเส้นทางให้ใหม่ทั้งชุด
     *
     * @param  array<int,array{code?:string,name:string,assignee_permission:string}>  $steps
     */
    public function saveDraft(WorkflowVersion $version, array $steps, User $actor): WorkflowVersion
    {
        $this->assertDraft($version);
        $this->assertStepsAreUsable($steps);

        return DB::transaction(function () use ($version, $steps, $actor) {
            $this->replaceSteps($version, $steps);

            $this->audit->log('saraban.workflow.draft_saved', $version, $version->id, null, [
                'version' => $version->version,
                'steps' => count($steps),
            ], null, $actor->id);

            return $version->fresh();
        }, 5);
    }

    /**
     * เผยแพร่ฉบับร่างให้มีผลบังคับใช้
     *
     * เรื่องที่กำลังเดินอยู่ยังใช้เวอร์ชันเดิมจนจบ — ไม่มีเรื่องใดเปลี่ยนสายกลางคัน
     */
    public function publish(WorkflowVersion $version, User $actor): WorkflowVersion
    {
        $this->assertDraft($version);

        $steps = $version->steps()->get();

        if ($steps->isEmpty()) {
            throw new WorkflowDesignException('สายงานต้องมีอย่างน้อยหนึ่งขั้นจึงจะเผยแพร่ได้');
        }

        return DB::transaction(function () use ($version, $actor) {
            $definition = $version->definition;

            $previous = $definition->versions()
                ->where('status', WorkflowVersion::STATUS_ACTIVE)
                ->get();

            $version->update([
                'status' => WorkflowVersion::STATUS_ACTIVE,
                'effective_from' => $this->clock->now()->toDateString(),
                'published_by' => $actor->id,
                'published_at' => $this->clock->now(),
            ]);

            /*
             * ปลดเวอร์ชันเดิม **หลัง** เปิดใช้เวอร์ชันใหม่ — ถ้าปลดก่อน จะมีช่วงที่
             * ไม่มีเวอร์ชันใดใช้งานได้ และเรื่องที่เสนอในช่วงนั้นเริ่มสายไม่ได้
             */
            foreach ($previous as $old) {
                $old->update([
                    'status' => WorkflowVersion::STATUS_RETIRED,
                    'effective_to' => $this->clock->now()->toDateString(),
                ]);
            }

            $this->audit->log('saraban.workflow.published', $version, $version->id, null, [
                'version' => $version->version,
                'retired' => $previous->pluck('version')->all(),
            ], null, $actor->id);

            return $version->fresh();
        }, 5);
    }

    /** ทิ้งฉบับร่าง — ทำได้เฉพาะฉบับร่างเท่านั้น */
    public function discardDraft(WorkflowVersion $version, User $actor): void
    {
        $this->assertDraft($version);

        $this->audit->log('saraban.workflow.draft_discarded', $version, $version->id, null, [
            'version' => $version->version,
        ], null, $actor->id);

        $version->delete();
    }

    /**
     * เขียนขั้นตอนใหม่ทั้งชุด แล้วผูกเส้นทางตามลำดับ
     *
     * ลบของเดิมทิ้งทั้งหมดก่อนเสมอ เพราะ `(version, step_order)` เป็น unique
     * การสลับลำดับแบบแก้ทีละแถวจะชนกันเองระหว่างทาง
     *
     * @param  array<int,array{code?:string,name:string,assignee_permission:string}>  $steps
     */
    private function replaceSteps(WorkflowVersion $version, array $steps): void
    {
        // transitions ผูก cascade กับ step อยู่แล้ว แต่ลบเองก่อนให้ลำดับชัดเจน
        $version->transitions()->delete();
        $version->steps()->delete();

        $created = [];

        foreach (array_values($steps) as $index => $step) {
            $order = $index + 1;

            $created[] = WorkflowStep::create([
                'workflow_version_id' => $version->id,
                'code' => $step['code'] ?? 'step_'.$order,
                'name' => $step['name'],
                'step_order' => $order,
                'type' => WorkflowStep::TYPE_APPROVAL,
                'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
                'assignee_permission' => $step['assignee_permission'],
                'completion_policy' => 'any',
                'is_final' => $order === count($steps),
            ]);
        }

        $this->wireTransitions($version, $created);
    }

    /**
     * ผูกเส้นทางมาตรฐานจากลำดับขั้น
     *
     * กติกาที่ใช้ — เหมือนกันทุกสายงาน จึงคาดเดาได้และอธิบายให้ผู้ใช้ฟังได้:
     *
     *   - **เห็นชอบ** ขั้นที่ i ไปขั้นที่ i+1 · ขั้นสุดท้ายจบเรื่องเป็น "ลงนามแล้ว"
     *   - **ส่งกลับ** ขั้นแรกคืนเรื่องให้ผู้ยื่น · ขั้นอื่นย้อนไปขั้นก่อนหน้า
     *     เพราะผู้พิจารณาต้องการให้แก้แล้วเสนอใหม่ ไม่ใช่ตีตกทั้งเรื่อง
     *   - **ไม่เห็นชอบ** จบเรื่องได้จากทุกขั้น
     *
     * ทั้งส่งกลับและไม่เห็นชอบบังคับให้ระบุเหตุผล — เรื่องที่ถูกตีกลับโดยไม่มี
     * คำอธิบายคือเรื่องที่ผู้ยื่นแก้ไม่ถูก และจะวนกลับมาอีก
     *
     * @param  array<int,WorkflowStep>  $steps
     */
    private function wireTransitions(WorkflowVersion $version, array $steps): void
    {
        $last = count($steps) - 1;
        $order = 0;

        foreach ($steps as $index => $step) {
            $next = $steps[$index + 1] ?? null;

            WorkflowTransition::create([
                'workflow_version_id' => $version->id,
                'from_step_id' => $step->id,
                'to_step_id' => $next?->id,
                'action' => WorkflowTransition::APPROVE,
                'name' => $index === $last ? 'ลงนาม' : "เห็นชอบ เสนอ{$next->name}",
                'result' => $index === $last ? 'completed' : null,
                'sort_order' => $order++,
                'requires_comment' => false,
            ]);

            $previous = $steps[$index - 1] ?? null;

            WorkflowTransition::create([
                'workflow_version_id' => $version->id,
                'from_step_id' => $step->id,
                'to_step_id' => $previous?->id,
                'action' => WorkflowTransition::RETURN,
                'name' => $previous === null ? 'ส่งกลับแก้ไข' : "ส่งกลับให้{$previous->name}",
                'result' => $previous === null ? 'returned' : null,
                'sort_order' => $order++,
                'requires_comment' => true,
            ]);

            WorkflowTransition::create([
                'workflow_version_id' => $version->id,
                'from_step_id' => $step->id,
                'to_step_id' => null,
                'action' => WorkflowTransition::REJECT,
                'name' => 'ไม่เห็นชอบ',
                'result' => 'rejected',
                'sort_order' => $order++,
                'requires_comment' => true,
            ]);
        }
    }

    private function assertDraft(WorkflowVersion $version): void
    {
        if ($version->status !== WorkflowVersion::STATUS_DRAFT) {
            throw new WorkflowDesignException(
                'แก้ไขได้เฉพาะฉบับร่าง — เวอร์ชันที่ใช้งานแล้วเป็นหลักฐานว่าเรื่องในอดีตเดินตามกติกาใด'
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $steps
     */
    private function assertStepsAreUsable(array $steps): void
    {
        if ($steps === []) {
            throw new WorkflowDesignException('สายงานต้องมีอย่างน้อยหนึ่งขั้น');
        }

        if (count($steps) > self::MAX_STEPS) {
            throw new WorkflowDesignException('สายงานมีได้ไม่เกิน '.self::MAX_STEPS.' ขั้น');
        }

        $codes = array_filter(array_column($steps, 'code'));

        if (count($codes) !== count(array_unique($codes))) {
            throw new WorkflowDesignException('รหัสขั้นตอนซ้ำกัน');
        }

        /*
         * **เฉพาะสิทธิ์ในสารบัญของสารบรรณ** — ถ้าปล่อยให้พิมพ์ชื่อสิทธิ์อะไรก็ได้
         * จะได้ขั้นตอนที่ไม่มีใครถือสิทธิ์นั้นเลย เรื่องจะค้างโดยไม่มีข้อความบอก
         * และยังเป็นช่องให้สายงานของสารบรรณไปอ้างสิทธิ์ของโมดูลอื่น
         */
        $allowed = array_keys(app(ModuleRegistry::class)->get('saraban')->permissions());

        foreach ($steps as $step) {
            if (! in_array($step['assignee_permission'], $allowed, true)) {
                throw new WorkflowDesignException(
                    "สิทธิ์ \"{$step['assignee_permission']}\" ไม่อยู่ในสารบัญสิทธิ์ของงานสารบรรณ"
                );
            }
        }
    }
}
