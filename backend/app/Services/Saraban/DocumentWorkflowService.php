<?php

namespace App\Services\Saraban;

use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowTransition;
use App\Services\AuditLogger;
use App\Services\TrustedClock;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * ผูกหนังสือเข้ากับ workflow engine ของระบบเดิม (SoT §11, §12 · INT-08)
 *
 * **ไม่มี state machine ของตัวเอง** — สถานะการเดินเรื่องทั้งหมดอยู่ใน
 * `workflow_instances` / `workflow_tasks` / `workflow_actions` ของ engine กลาง
 * คลาสนี้ทำสองอย่างเท่านั้น: เริ่มสายให้หนังสือ และแปลผลของ engine
 * กลับมาเป็นสถานะของหนังสือ
 *
 * ถ้าเก็บสถานะการเดินเรื่องไว้ในตารางของสารบรรณอีกชุด จะกลายเป็นความจริงสองชุด
 * ที่หลุดจากกันทันทีที่มีการส่งกลับหรือมอบอำนาจ
 */
class DocumentWorkflowService
{
    public const DEFINITION_CODE = 'saraban.document';

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly TrustedClock $clock,
    ) {}

    /** เริ่มสายการเดินหนังสือให้หนังสือฉบับนี้ */
    public function start(Document $document, User $initiator): WorkflowInstance
    {
        $definition = WorkflowDefinition::query()->where('code', self::DEFINITION_CODE)->firstOrFail();

        return $this->engine->start($definition, $document, $initiator->id);
    }

    public function instanceFor(Document $document): ?WorkflowInstance
    {
        return $this->engine->instanceFor($document, self::DEFINITION_CODE);
    }

    /**
     * สถานะการเดินเรื่องของหนังสือฉบับนี้ สำหรับแสดงบนหน้าจอ
     *
     * @return array<string,mixed>
     */
    public function stateFor(Document $document, User $viewer): array
    {
        $instance = $this->instanceFor($document);

        if ($instance === null) {
            return ['started' => false, 'status' => null, 'current_step' => null, 'transitions' => [], 'history' => []];
        }

        $step = $this->engine->currentStep($instance);

        return [
            'started' => true,
            'status' => $instance->status,
            'current_step' => $step === null ? null : ['code' => $step->code, 'name' => $step->name, 'order' => $step->step_order],
            'transitions' => array_map(
                fn (WorkflowTransition $transition) => [
                    'action' => $transition->action,
                    'name' => $transition->name,
                    'requires_comment' => (bool) $transition->requires_comment,
                ],
                WorkflowTransition::query()
                    ->where('workflow_version_id', $instance->workflow_version_id)
                    ->where('from_step_id', $step?->id)
                    ->whereIn('action', $this->engine->availableTransitions($instance, $viewer))
                    ->orderBy('sort_order')
                    ->get()->all(),
            ),
            'history' => $instance->actions()
                ->with('actor:id,name')
                ->latest('created_at')
                ->get()
                ->map(fn ($action) => [
                    'action' => $action->action,
                    'actor' => $action->actor?->name,
                    'comment' => $action->comment,
                    'at' => $action->created_at,
                ])
                ->all(),
        ];
    }

    /**
     * ลงมือทำขั้นปัจจุบัน แล้วสะท้อนผลกลับมาที่หนังสือ
     *
     * @return array<string,mixed>
     */
    public function perform(Document $document, string $action, User $actor, ?string $comment = null): array
    {
        $instance = $this->instanceFor($document);

        if ($instance === null) {
            throw new NumberingException('หนังสือฉบับนี้ยังไม่ได้เข้าสู่กระบวนการ');
        }

        return DB::transaction(function () use ($document, $instance, $action, $actor, $comment) {
            $result = $this->engine->perform($instance, $action, $actor, $comment);

            $this->syncDocumentStatus($document, $result['instance'], $actor);

            $this->audit->log("saraban.workflow.{$action}", $document, $document->id, null, [
                'instance_status' => $result['instance']->status,
                'next_step' => $result['next_step']?->code,
            ], $comment, $actor->id);

            return $result;
        }, 5);
    }

    /**
     * แปลผลของ engine กลับมาเป็นสถานะของหนังสือ
     *
     * **เลขหนังสือถูกออกที่นี่ ไม่ใช่ตอนสร้างหนังสือ** — ตามระเบียบงานสารบรรณ
     * เลขที่หนังสือเป็นของหนังสือที่ผ่านการลงนามแล้ว การออกเลขให้ฉบับร่างที่อาจ
     * ไม่ได้ลงนามจะทำให้ทะเบียนมีเลขที่ไม่มีหนังสือจริงรองรับ
     */
    private function syncDocumentStatus(Document $document, WorkflowInstance $instance, User $actor): void
    {
        $status = match ($instance->status) {
            WorkflowInstance::STATUS_COMPLETED => Document::STATUS_SIGNED,
            WorkflowInstance::STATUS_REJECTED => Document::STATUS_REJECTED,
            WorkflowInstance::STATUS_RETURNED => Document::STATUS_DRAFT,
            WorkflowInstance::STATUS_CANCELLED => Document::STATUS_CANCELLED,
            default => Document::STATUS_SUBMITTED,
        };

        $document->update(['status' => $status]);

        if ($status === Document::STATUS_SIGNED && $document->document_number_id === null) {
            $this->issueDocumentNumber($document, $actor);
        }
    }

    /**
     * ออกเลขหนังสือให้ฉบับที่ลงนามแล้ว
     *
     * ถ้ายังไม่ได้ตั้งค่าชุดเลขของประเภทนี้ จะ **ล้มพร้อมข้อความบอกเหตุ**
     * ไม่ใช่ลงนามผ่านไปเงียบ ๆ โดยไม่มีเลข — หนังสือที่ลงนามแล้วแต่ไม่มีเลขที่
     * ใช้เป็นหนังสือราชการไม่ได้ และจะกลายเป็นงานค้างที่ไม่มีใครรู้
     */
    private function issueDocumentNumber(Document $document, User $actor): void
    {
        /*
         * **เลขที่เจ้าหน้าที่จองไว้ให้หนังสือฉบับนี้มาก่อนเสมอ** (SoT §9)
         *
         * ก่อนหน้านี้ที่นี่จองเลขใหม่ทุกครั้ง เลขที่จองล่วงหน้าจึงไม่มีทางไปอยู่บน
         * หนังสือได้เลย — หน้าจองเลขกลายเป็นหน้าที่ทำได้แค่จองแล้วคืน · งานสารบรรณ
         * จริงจองเลขไว้ก่อนร่างเสร็จเพื่อพิมพ์ลงหัวหนังสือ ถ้าระบบออกเลขอื่นให้ตอน
         * ลงนาม เลขบนกระดาษกับเลขในทะเบียนจะไม่ตรงกัน
         */
        $allocated = DocumentNumber::query()
            ->where('document_id', $document->id)
            ->where('status', DocumentNumber::STATUS_ALLOCATED)
            ->first();

        if ($allocated !== null) {
            $issued = $this->numbering->issue($allocated, $actor);

            $document->update([
                'document_number_id' => $issued->id,
                'issued_at' => $this->clock->now(),
            ]);

            return;
        }

        $category = $document->documentType?->category ?? DocumentType::CATEGORY_OUTGOING;

        $series = NumberSeries::query()
            ->where('is_active', true)
            ->where('category', $category)
            ->where(fn ($query) => $query
                ->where('org_unit_id', $document->owner_org_unit_id)
                ->orWhereNull('org_unit_id'))
            // ชุดเลขเฉพาะหน่วยงานมาก่อนชุดเลขระดับองค์กร
            ->orderByRaw('org_unit_id is null')
            ->first();

        if ($series === null) {
            throw new NumberingException(
                "ยังไม่ได้ตั้งค่าชุดเลขหนังสือสำหรับประเภท \"{$category}\" จึงออกเลขให้หนังสือที่ลงนามแล้วไม่ได้ กรุณาติดต่อผู้ดูแลระบบ"
            );
        }

        $number = $this->numbering->reserve($series, $actor);
        $number = $this->numbering->allocate($number, $document, $actor);
        $number = $this->numbering->issue($number, $actor);

        $document->update([
            'document_number_id' => $number->id,
            'issued_at' => $this->clock->now(),
        ]);
    }
}
