<?php

namespace Tests\Feature\Saraban;

use App\Models\Department;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Services\Saraban\DocumentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkflowTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentWorkflowService $service;
    protected User $user;
    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DocumentWorkflowService::class);
        $this->user = User::factory()->create();

        $department = Department::create(['name' => 'ฝ่ายบริหาร', 'status' => 'active']);
        $type = DocumentType::create(['name' => 'หนังสือภายใน', 'code' => 'int']);

        $this->document = Document::create([
            'document_number' => 'int 0001/2569',
            'title' => 'เอกสารทดสอบ',
            'type_id' => $type->id,
            'priority' => 'normal',
            'secrecy' => 'normal',
            'status' => 'draft',
            'created_by' => $this->user->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_legal_transition_is_recorded(): void
    {
        $this->service->transitionState($this->document, 'pending_review', $this->user->id, 'submitted');

        $this->assertSame('pending_review', $this->document->fresh()->status);
        $this->assertDatabaseHas('saraban_document_workflow_steps', [
            'document_id' => $this->document->id,
            'action' => 'submitted',
            'step_order' => 1,
        ]);
        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $this->document->id,
            'action' => 'document.submitted',
        ]);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // draft → signed ข้ามขั้นตอนทั้งหมด ต้องถูกปฏิเสธ
        $this->service->transitionState($this->document, 'signed', $this->user->id, 'signed');
    }

    public function test_archived_document_cannot_transition(): void
    {
        $this->document->update(['status' => 'archived']);

        $this->expectException(ValidationException::class);

        $this->service->transitionState($this->document, 'draft', $this->user->id, 'rejected');
    }

    public function test_document_in_draft_cannot_be_signed(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->signDocument($this->document, $this->user);
    }

    public function test_full_happy_path_reaches_signed(): void
    {
        $this->service->transitionState($this->document, 'pending_review', $this->user->id, 'submitted');
        $this->service->transitionState($this->document, 'pending_approval', $this->user->id, 'approved');
        $this->service->transitionState($this->document, 'approved_pending_signature', $this->user->id, 'approved');

        $hash = $this->service->signDocument($this->document, $this->user);

        $this->assertSame('signed', $this->document->fresh()->status);
        $this->assertDatabaseHas('saraban_document_signatures', [
            'document_id' => $this->document->id,
            'signature_hash' => $hash,
        ]);
        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $this->document->id,
            'action' => 'document.signed',
        ]);
    }
}
