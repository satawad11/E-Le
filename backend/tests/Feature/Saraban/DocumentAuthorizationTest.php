<?php

namespace Tests\Feature\Saraban;

class DocumentAuthorizationTest extends SarabanTestCase
{
    public function test_unrelated_staff_cannot_update_someone_elses_document(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->outsider)
            ->putJson("/api/saraban/documents/{$document->id}", [
                'title' => 'Hijacked',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('saraban_documents', [
            'id' => $document->id,
            'title' => 'Test Document',
        ]);
    }

    public function test_unrelated_staff_cannot_delete_someone_elses_document(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->outsider)
            ->deleteJson("/api/saraban/documents/{$document->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('saraban_documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_staff_without_delete_permission_is_blocked_by_route_middleware(): void
    {
        // The `staff` role is granted view/create/edit but not delete.
        $document = $this->makeDocument();

        $this->actingAs($this->staff)
            ->deleteJson("/api/saraban/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_bulk_delete_authorizes_every_document(): void
    {
        $mine = $this->makeDocument(['created_by' => $this->registrar->id]);
        $theirs = $this->makeDocument(['created_by' => $this->outsider->id]);

        // The registrar may delete both, so this one succeeds.
        $this->actingAs($this->registrar)
            ->deleteJson('/api/saraban/documents/bulk', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk();

        $this->assertSoftDeleted('saraban_documents', ['id' => $mine->id]);
        $this->assertSoftDeleted('saraban_documents', ['id' => $theirs->id]);
    }

    public function test_signed_documents_cannot_be_edited_or_deleted(): void
    {
        $document = $this->makeDocument([
            'status' => 'signed',
            'created_by' => $this->registrar->id,
        ]);

        $this->actingAs($this->registrar)
            ->putJson("/api/saraban/documents/{$document->id}", [
                'title' => 'Changed after signing',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertForbidden();

        $this->actingAs($this->registrar)
            ->deleteJson("/api/saraban/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_update_cannot_reassign_the_document_number(): void
    {
        $document = $this->makeDocument([
            'created_by' => $this->staff->id,
            'document_number' => 'int 0001/2569',
        ]);

        $this->actingAs($this->staff)
            ->putJson("/api/saraban/documents/{$document->id}", [
                'title' => 'Renamed',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
                'document_number' => 'int 9999/2569',
            ])
            ->assertOk();

        $this->assertDatabaseHas('saraban_documents', [
            'id' => $document->id,
            'document_number' => 'int 0001/2569',
        ]);
    }

    public function test_staff_cannot_manage_document_types(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/saraban/types', ['name' => 'ประเภทใหม่'])
            ->assertForbidden();
    }

    public function test_document_type_in_use_cannot_be_deleted(): void
    {
        $this->makeDocument();

        $this->actingAs($this->registrar)
            ->deleteJson("/api/saraban/types/{$this->type->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('saraban_document_types', ['id' => $this->type->id]);
    }
}
