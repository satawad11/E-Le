<?php

namespace Tests\Feature\Saraban;

class DocumentContentTest extends SarabanTestCase
{
    /**
     * Regression: content / creation_mode / template_type were missing from
     * Document::$fillable, so the body of a digitally-authored document was
     * silently dropped and could never be edited again.
     */
    public function test_digital_document_body_is_persisted(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents', [
                'title' => 'หนังสือพิมพ์ในระบบ',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
                'creation_mode' => 'digital',
                'template_type' => 'internal',
                'content' => '<p>เรียน ผู้อำนวยการ</p>',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('saraban_documents', [
            'title' => 'หนังสือพิมพ์ในระบบ',
            'creation_mode' => 'digital',
            'template_type' => 'internal',
            'content' => '<p>เรียน ผู้อำนวยการ</p>',
        ]);
    }

    public function test_creating_a_document_without_creation_mode_succeeds(): void
    {
        // Regression: $data['creation_mode'] was read unguarded on a nullable rule.
        $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents', [
                'title' => 'หนังสือแนบไฟล์',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertCreated();
    }

    public function test_new_documents_start_in_draft_so_the_workflow_can_run(): void
    {
        $id = $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents', [
                'title' => 'หนังสือใหม่',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertCreated()
            ->json('id');

        $this->assertDatabaseHas('saraban_documents', ['id' => $id, 'status' => 'draft']);

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$id}/workflow/submit")
            ->assertOk();

        $this->assertDatabaseHas('saraban_documents', ['id' => $id, 'status' => 'pending_review']);
    }

    public function test_document_number_uses_the_thai_calendar_year(): void
    {
        $expectedYear = (int) date('Y') + 543;

        $number = $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents', [
                'title' => 'หนังสือออกเลข',
                'type_id' => $this->type->id,
                'department_id' => $this->department->id,
                'priority' => 'normal',
                'secrecy' => 'normal',
            ])
            ->assertCreated()
            ->json('document_number');

        $this->assertStringEndsWith("/{$expectedYear}", $number);
    }

    public function test_incoming_and_outgoing_filters_return_different_sets(): void
    {
        $incoming = $this->makeDocument([
            'title' => 'หนังสือรับ',
            'incoming_document_number' => 'ศธ 0584/123',
            'incoming_date' => now()->toDateString(),
        ]);
        $outgoing = $this->makeDocument(['title' => 'หนังสือส่ง']);

        $incomingIds = collect(
            $this->actingAs($this->registrar)
                ->getJson('/api/saraban/documents?direction=incoming')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $outgoingIds = collect(
            $this->actingAs($this->registrar)
                ->getJson('/api/saraban/documents?direction=outgoing')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertTrue($incomingIds->contains($incoming->id));
        $this->assertFalse($incomingIds->contains($outgoing->id));

        $this->assertTrue($outgoingIds->contains($outgoing->id));
        $this->assertFalse($outgoingIds->contains($incoming->id));
    }
}
