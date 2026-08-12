<?php

namespace Tests\Feature\Saraban;

class AuditLogTest extends SarabanTestCase
{
    public function test_creating_a_document_writes_an_audit_row(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents', [
                'title' => 'Audited Document',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('saraban_audit_logs', [
            'user_id' => $this->staff->id,
            'action' => 'document.created',
        ]);
    }

    public function test_updating_a_document_writes_an_audit_row(): void
    {
        $document = $this->makeDocument(['created_by' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->putJson("/api/saraban/documents/{$document->id}", [
                'title' => 'Updated title',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertOk();

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'user_id' => $this->staff->id,
            'action' => 'document.updated',
        ]);
    }

    public function test_deleting_a_document_writes_an_audit_row(): void
    {
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);

        $this->actingAs($this->registrar)
            ->deleteJson("/api/saraban/documents/{$document->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'user_id' => $this->registrar->id,
            'action' => 'document.deleted',
        ]);
    }

    public function test_workflow_actions_write_audit_rows(): void
    {
        $document = $this->makeDocument(['created_by' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/workflow/submit", ['comment' => 'ส่งตรวจ'])
            ->assertOk();

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/workflow/approve", ['comment' => 'อนุมัติ'])
            ->assertOk();

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'action' => 'document.submitted',
        ]);
        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'action' => 'document.approved',
        ]);
    }

    public function test_report_export_writes_an_audit_row(): void
    {
        $this->makeDocument();

        $this->actingAs($this->registrar)
            ->get('/api/saraban/reports/export?year='.date('Y'))
            ->assertOk();

        $this->assertDatabaseHas('saraban_audit_logs', [
            'user_id' => $this->registrar->id,
            'action' => 'report.exported',
        ]);
    }
}
