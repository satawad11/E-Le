<?php

namespace App\Services\Workflow;

use App\Models\Saraban\Document;
use App\Models\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTask;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-05 · 05-5 — เตรียมงานสารบรรณเข้า Workflow Engine เดียวกัน
 *
 * **สถานะ: draft เท่านั้น — ยังไม่มีผลกับระบบจริง**
 *
 * `DocumentWorkflowService` ยังเป็นผู้ตัดสินสถานะหนังสือทั้งหมดเหมือนเดิม
 * ไฟล์นี้แค่บันทึกว่า "สายงานของสารบรรณที่ใช้อยู่ หน้าตาเป็นอย่างไร"
 * ในรูปแบบของ engine เพื่อให้ขั้นตอน integration จริงมีจุดตั้งต้นที่ตรวจสอบได้
 *
 * เหตุผลที่ยังไม่เปิดใช้:
 *   - โจทย์ของเฟสนี้สั่งไว้ชัดว่า **ห้ามเปลี่ยน business permission ที่ปิดไว้
 *     ใน LEGACY-04** โดยเฉพาะสิทธิ์ของ Document Officer / ผอ. / รอง ผอ.
 *     สิทธิ์ลงนาม และสิทธิ์เอกสารลับ
 *   - LEGACY-04 ปิดงานโดย **ยังไม่ได้** ให้สิทธิ์ `saraban.document.approve`
 *     และ `.sign` แก่ ผอ./รอง ผอ. (รอการตัดสินใจ) ถ้าเดินสายนี้จริงตอนนี้
 *     จะไม่มีผู้อนุมัติในสายเลยนอกจาก Super Admin
 *
 * สายงานที่บันทึกไว้สะท้อน `DocumentWorkflowService` ตามจริง:
 *
 *     draft ──submit──▶ pending_review ──approve──▶ pending_approval
 *           ◀──reject──                ◀──reject──
 *                                                 ──approve──▶ approved_pending_signature
 *                                                 ──sign─────▶ signed
 */
class SarabanWorkflowProvisioner
{
    public const DEFINITION_CODE = 'saraban.document';

    /**
     * ขั้นตอน => [ชื่อ, permission ที่คุมขั้นนั้นอยู่จริงวันนี้]
     *
     * ยึดตาม middleware ของ route สารบรรณ ณ วันที่เขียน (หลัง LEGACY-04)
     * ไม่ได้เปลี่ยนแปลงสิทธิ์ใด ๆ เพียงบันทึกว่าใครคุมขั้นไหน
     */
    private const STEPS = [
        ['code' => 'draft', 'name' => 'ร่างหนังสือ', 'permission' => 'saraban.document.create'],
        ['code' => 'pending_review', 'name' => 'รอตรวจสอบโดยเจ้าหน้าที่สารบรรณ', 'permission' => 'saraban.document.register'],
        ['code' => 'pending_approval', 'name' => 'รอกลั่นกรอง/อนุมัติ', 'permission' => 'saraban.document.approve'],
        ['code' => 'approved_pending_signature', 'name' => 'รอลงนาม', 'permission' => 'saraban.document.sign'],
        ['code' => 'signed', 'name' => 'ลงนามแล้ว', 'permission' => null],
    ];

    public function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::firstOrCreate(
            ['code' => self::DEFINITION_CODE],
            [
                'module' => 'saraban',
                'name' => 'สายงานหนังสือราชการ',
                'subject_type' => (new Document)->getMorphClass(),
                'description' => 'สายงานที่ DocumentWorkflowService ใช้อยู่ (CORE-WF-002)',
                'is_active' => true,
            ],
        );
    }

    /**
     * บันทึกสายงานสารบรรณเป็น definition + version ที่ใช้งานได้จริง
     *
     * CORE CHECKPOINT (2026-08-14): เดิมออกเป็น `draft` เท่านั้นเพราะยังไม่มี
     * ผู้อนุมัติในสาย (ผอ./รอง ผอ. ยังไม่ได้สิทธิ์ `saraban.document.approve`)
     * เมื่อสิทธิ์นั้นได้รับอนุมัติแล้ว จึงเปิดเป็น `active` เพื่อให้เอกสาร
     * **pin เวอร์ชันของตัวเองได้** ซึ่งเป็นสิ่งที่ `CORE-WF-002` ต้องการ
     *
     * idempotent — เรียกซ้ำได้ ไม่สร้างซ้ำ
     */
    public function publishCurrentVersion(): WorkflowVersion
    {
        $definition = $this->definition();

        if (! $definition->is_active) {
            $definition->update(['is_active' => true]);
        }

        $existing = $definition->activeVersion();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($definition) {
            // เวอร์ชัน draft ที่ LEGACY-05 เคยออกไว้ ให้เลื่อนขึ้นเป็น active แทนการออกใหม่
            $draft = $definition->versions()->where('status', WorkflowVersion::STATUS_DRAFT)->orderByDesc('version')->first();

            if ($draft !== null) {
                $draft->update([
                    'status' => WorkflowVersion::STATUS_ACTIVE,
                    'effective_from' => now()->toDateString(),
                    'published_at' => now(),
                    'notes' => 'เปิดใช้เมื่อ CORE CHECKPOINT หลังผู้บริหารได้รับสิทธิ์อนุมัติ/ลงนาม',
                ]);

                return $draft;
            }

            $version = WorkflowVersion::create([
                'workflow_definition_id' => $definition->getKey(),
                'version' => (int) $definition->versions()->max('version') + 1,
                'status' => WorkflowVersion::STATUS_ACTIVE,
                'effective_from' => now()->toDateString(),
                'published_at' => now(),
                'notes' => 'สะท้อนสายงานของ DocumentWorkflowService (CORE-WF-002)',
            ]);

            $steps = [];

            foreach (self::STEPS as $index => $definitionStep) {
                $steps[$definitionStep['code']] = WorkflowStep::create([
                    'workflow_version_id' => $version->getKey(),
                    'code' => $definitionStep['code'],
                    'name' => $definitionStep['name'],
                    'step_order' => $index + 1,
                    'type' => WorkflowStep::TYPE_APPROVAL,
                    'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
                    'assignee_permission' => $definitionStep['permission'],
                    'is_final' => $definitionStep['code'] === 'signed',
                ]);
            }

            $edges = [
                ['draft', 'pending_review', WorkflowTransition::APPROVE, 'ส่งตรวจสอบ', null],
                ['pending_review', 'pending_approval', WorkflowTransition::APPROVE, 'ผ่านการตรวจสอบ', null],
                ['pending_review', 'draft', WorkflowTransition::REJECT, 'ตีกลับให้แก้ไข', null],
                ['pending_approval', 'approved_pending_signature', WorkflowTransition::APPROVE, 'อนุมัติ', null],
                ['pending_approval', 'draft', WorkflowTransition::REJECT, 'ตีกลับให้แก้ไข', null],
                ['approved_pending_signature', 'signed', WorkflowTransition::APPROVE, 'ลงนาม', WorkflowInstance::STATUS_COMPLETED],
            ];

            foreach ($edges as $sortOrder => [$from, $to, $action, $name, $result]) {
                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $steps[$from]->getKey(),
                    'to_step_id' => $steps[$to]->getKey(),
                    'action' => $action,
                    'name' => $name,
                    'result' => $result,
                    'requires_comment' => $action === WorkflowTransition::REJECT,
                    'sort_order' => $sortOrder,
                ]);
            }

            return $version;
        }, 5);
    }

    /**
     * ผูกเอกสารเข้ากับ workflow version — **หัวใจของ CORE-WF-002**
     *
     * เอกสารแต่ละฉบับ pin `workflow_version_id` ของตัวเองไว้ตอนเข้าสาย
     * การแก้สายงานภายหลังจึงออกเป็นเวอร์ชันใหม่และไม่ย้อนไปเปลี่ยนความหมาย
     * ของเอกสารที่กำลังเดินอยู่ ตาม `docs/07-WORKFLOW-ENGINE.md`
     *
     * idempotent — เอกสารเดิมได้ instance เดิมเสมอ
     */
    public function pin(Document $document): ?WorkflowInstance
    {
        $version = $this->definition()->activeVersion();

        if ($version === null) {
            return null;
        }

        $existing = WorkflowInstance::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $step = $this->stepFor($version, $document->status);

        if ($step === null) {
            return null;
        }

        return DB::transaction(function () use ($document, $version, $step) {
            $instance = WorkflowInstance::create([
                'workflow_version_id' => $version->getKey(),
                'subject_type' => $document->getMorphClass(),
                'subject_id' => $document->getKey(),
                'status' => $step->is_final ? WorkflowInstance::STATUS_COMPLETED : WorkflowInstance::STATUS_RUNNING,
                'current_step_id' => $step->is_final ? null : $step->getKey(),
                'initiated_by' => $document->created_by,
                // ยังเป็น shadow — `DocumentWorkflowService` ยังตัดสินสถานะจริง
                'is_shadow' => true,
                'started_at' => $document->created_at ?? now(),
                'completed_at' => $step->is_final ? ($document->updated_at ?? now()) : null,
                'meta' => ['pinned_from_status' => $document->status],
            ]);

            if (! $step->is_final) {
                WorkflowTask::create([
                    'workflow_instance_id' => $instance->getKey(),
                    'workflow_step_id' => $step->getKey(),
                    'status' => WorkflowTask::STATUS_PENDING,
                    'sequence' => (int) $step->step_order,
                ]);
            }

            WorkflowAction::create([
                'workflow_instance_id' => $instance->getKey(),
                'action' => 'pinned',
                'to_step_id' => $step->getKey(),
                'correlation_id' => request()?->attributes?->get('request_id'),
                'context' => ['status' => $document->status],
            ]);

            return $instance;
        }, 5);
    }

    /**
     * ขยับ instance ให้ตรงกับสถานะที่ `DocumentWorkflowService` เพิ่งตั้ง
     *
     * เรียกหลังจากตรรกะเดิมทำงานเสร็จแล้วเท่านั้น — ไม่แตะเอกสาร
     * ไม่แตะ `saraban_document_workflow_steps` และไม่มีทางทำให้ transition จริงล้ม
     */
    public function syncState(Document $document, string $newStatus, ?int $actorId = null, ?string $comment = null): ?WorkflowInstance
    {
        $instance = $this->pin($document);

        if ($instance === null) {
            return null;
        }

        // ใช้เวอร์ชันที่ instance pin ไว้ ไม่ใช่เวอร์ชันล่าสุด — นี่คือประเด็นทั้งหมดของ CORE-WF-002
        $target = $this->stepFor($instance->version, $newStatus);

        if ($target === null) {
            return $instance;
        }

        return DB::transaction(function () use ($instance, $target, $actorId, $comment) {
            $from = $instance->current_step_id;

            $instance->tasks()
                ->where('status', WorkflowTask::STATUS_PENDING)
                ->update([
                    'status' => WorkflowTask::STATUS_APPROVED,
                    'completed_by' => $actorId,
                    'effective_actor_id' => $actorId,
                    'completed_at' => now(),
                    'comment' => $comment,
                    'updated_at' => now(),
                ]);

            if ($target->is_final) {
                $instance->update([
                    'status' => WorkflowInstance::STATUS_COMPLETED,
                    'current_step_id' => null,
                    'completed_at' => now(),
                ]);
            } else {
                $instance->update([
                    'status' => WorkflowInstance::STATUS_RUNNING,
                    'current_step_id' => $target->getKey(),
                    'completed_at' => null,
                ]);

                WorkflowTask::create([
                    'workflow_instance_id' => $instance->getKey(),
                    'workflow_step_id' => $target->getKey(),
                    'status' => WorkflowTask::STATUS_PENDING,
                    'sequence' => (int) $target->step_order,
                ]);
            }

            WorkflowAction::create([
                'workflow_instance_id' => $instance->getKey(),
                'action' => 'state_changed',
                'actor_id' => $actorId,
                'effective_actor_id' => $actorId,
                'from_step_id' => $from,
                'to_step_id' => $target->getKey(),
                'comment' => $comment,
                'correlation_id' => request()?->attributes?->get('request_id'),
                'ip_address' => request()?->ip(),
                'context' => ['status' => $target->code],
            ]);

            return $instance->refresh();
        }, 5);
    }

    /** ขั้นตอนในเวอร์ชันที่ระบุซึ่งตรงกับสถานะของเอกสาร */
    private function stepFor(?WorkflowVersion $version, ?string $status): ?WorkflowStep
    {
        if ($version === null || $status === null) {
            return null;
        }

        return $version->steps()->where('code', $status)->first();
    }
}
