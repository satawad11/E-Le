<?php

namespace Tests\Feature\Workflow;

use App\Models\Department;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentWorkflowStep;
use App\Models\User;
use App\Models\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowVersion;
use App\Services\Saraban\DocumentWorkflowService;
use App\Services\Workflow\SarabanWorkflowProvisioner;
use Database\Seeders\OperationalPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CORE CHECKPOINT · CORE-WF-002 — version pinning ของสายงานหนังสือราชการ
 *
 * ข้อบกพร่องเดิม: สายงานสารบรรณไม่มี version การแก้ template จึงเปลี่ยนความหมาย
 * ของเอกสารที่กำลังเดินอยู่ทันที (ขัด ADR-002 และ `docs/07-WORKFLOW-ENGINE.md`)
 *
 * เทสต์ชุดนี้พิสูจน์สองเรื่อง:
 *   1. เอกสารแต่ละฉบับ pin เวอร์ชันของตัวเอง และการแก้สายงานไม่ย้อนไปกระทบ
 *   2. การผูก workflow **ไม่เปลี่ยนพฤติกรรมเดิมของโมดูลสารบรรณเลย**
 */
class SarabanWorkflowPinningTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OperationalPermissionSeeder::class);

        $this->department = Department::create(['name' => 'ฝ่ายทดสอบ', 'status' => 'active']);
        $this->type = DocumentType::create(['name' => 'หนังสือภายใน', 'code' => 'int']);

        app(SarabanWorkflowProvisioner::class)->publishCurrentVersion();
    }

    private function makeDocument(string $status = 'draft'): Document
    {
        return Document::create([
            'title' => 'เอกสารทดสอบ',
            'document_number' => 'TEST-'.Str::uuid(),
            'type_id' => $this->type->id,
            'department_id' => $this->department->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
            'status' => $status,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function provisioner(): SarabanWorkflowProvisioner
    {
        return app(SarabanWorkflowProvisioner::class);
    }

    // ----------------------------------------------------------- version pinning

    public function test_the_saraban_workflow_is_now_active_not_a_draft(): void
    {
        $definition = $this->provisioner()->definition();

        $this->assertTrue($definition->is_active);
        $this->assertNotNull($definition->activeVersion(), 'ต้องมีเวอร์ชันที่รับ instance ได้');
    }

    public function test_a_document_pins_the_version_that_was_active_when_it_entered_the_chain(): void
    {
        $document = $this->makeDocument();
        $instance = $this->provisioner()->pin($document);

        $this->assertNotNull($instance);
        $pinned = $instance->workflow_version_id;

        // สายงานถูกแก้ภายหลัง — ออกเวอร์ชันใหม่
        $newVersion = WorkflowVersion::create([
            'workflow_definition_id' => $this->provisioner()->definition()->getKey(),
            'version' => 99,
            'status' => WorkflowVersion::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
        ]);
        WorkflowStep::create([
            'workflow_version_id' => $newVersion->getKey(),
            'code' => 'draft',
            'name' => 'ร่าง (เวอร์ชันใหม่)',
            'step_order' => 1,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.document.create',
        ]);

        $this->assertSame(
            $pinned,
            $instance->fresh()->workflow_version_id,
            'เอกสารเดิมต้องยัง pin เวอร์ชันเดิมแม้จะมีเวอร์ชันใหม่แล้ว',
        );
    }

    public function test_pinning_is_idempotent(): void
    {
        $document = $this->makeDocument();

        $first = $this->provisioner()->pin($document);
        $second = $this->provisioner()->pin($document);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, WorkflowInstance::where('subject_id', $document->id)->count());
    }

    public function test_the_instance_follows_the_document_through_the_chain(): void
    {
        $document = $this->makeDocument();
        $actor = User::factory()->create();
        $service = app(DocumentWorkflowService::class);

        $service->submitDocument($document, $actor->id);
        $instance = $this->provisioner()->pin($document->fresh());
        $this->assertSame('pending_review', $instance->fresh()->currentStep->code);

        $service->approveDocument($document->fresh(), $actor->id);
        $this->assertSame('pending_approval', $instance->fresh()->currentStep->code);

        $service->approveDocument($document->fresh(), $actor->id);
        $this->assertSame('approved_pending_signature', $instance->fresh()->currentStep->code);
    }

    public function test_rejecting_moves_the_instance_back_to_draft(): void
    {
        $document = $this->makeDocument();
        $actor = User::factory()->create();
        $service = app(DocumentWorkflowService::class);

        $service->submitDocument($document, $actor->id);
        $service->rejectDocument($document->fresh(), $actor->id, 'เอกสารไม่ครบ');

        $instance = $this->provisioner()->pin($document->fresh());
        $this->assertSame('draft', $instance->fresh()->currentStep->code);
    }

    // ------------------------------------------------------------------ audit

    public function test_every_state_change_is_recorded_against_the_instance(): void
    {
        $document = $this->makeDocument();
        $actor = User::factory()->create();

        app(DocumentWorkflowService::class)->submitDocument($document, $actor->id);

        $instance = $this->provisioner()->pin($document->fresh());
        $action = WorkflowAction::where('workflow_instance_id', $instance->getKey())
            ->where('action', 'state_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($action, 'ต้องบันทึกการเปลี่ยนสถานะไว้');
        $this->assertSame($actor->id, $action->actor_id);
        $this->assertNotNull($action->to_step_id);
    }

    // ------------------------------- ต้องไม่เปลี่ยนพฤติกรรมเดิมของโมดูลสารบรรณ

    public function test_pinning_does_not_change_the_document_or_its_legacy_history(): void
    {
        $document = $this->makeDocument();
        $actor = User::factory()->create();

        app(DocumentWorkflowService::class)->submitDocument($document, $actor->id);

        $document->refresh();
        $this->assertSame('pending_review', $document->status, 'สถานะเอกสารต้องเป็นไปตามตรรกะเดิม');

        // ประวัติเดิมยังถูกบันทึกครบเหมือนเดิม
        $this->assertSame(
            1,
            DocumentWorkflowStep::where('document_id', $document->id)->where('action', 'submitted')->count(),
        );
    }

    public function test_turning_pinning_off_leaves_the_saraban_module_working(): void
    {
        config(['workflow.saraban_pinning' => false]);

        $document = $this->makeDocument();
        $actor = User::factory()->create();

        app(DocumentWorkflowService::class)->submitDocument($document, $actor->id);

        $this->assertSame('pending_review', $document->fresh()->status);
        $this->assertSame(0, WorkflowInstance::where('subject_id', $document->id)->count());
    }

    public function test_a_broken_pinning_never_breaks_a_transition(): void
    {
        // ไม่มีเวอร์ชันที่ใช้ได้ — pin ต้องยอมแพ้เงียบ ๆ ไม่ทำให้การส่งหนังสือล้ม
        WorkflowVersion::query()->update(['status' => WorkflowVersion::STATUS_RETIRED]);

        $document = $this->makeDocument();
        $actor = User::factory()->create();

        app(DocumentWorkflowService::class)->submitDocument($document, $actor->id);

        $this->assertSame('pending_review', $document->fresh()->status);
    }
}
