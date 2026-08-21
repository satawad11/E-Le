<?php

namespace Tests\Feature\Saraban;

use App\Models\Saraban\DocumentAttachment;
use Illuminate\Support\Facades\Storage;

class ClassifiedDocumentTest extends SarabanTestCase
{
    public function test_uncleared_user_cannot_open_a_classified_document(): void
    {
        // Authored by the staff user, so only the classification blocks them.
        $document = $this->makeDocument([
            'secrecy' => 'secret',
            'created_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_cleared_user_can_open_a_classified_document(): void
    {
        $document = $this->makeDocument(['secrecy' => 'secret']);

        $this->actingAs($this->registrar)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertOk();
    }

    public function test_classified_documents_are_hidden_from_the_list(): void
    {
        $open = $this->makeDocument(['title' => 'Open document']);
        $classified = $this->makeDocument([
            'title' => 'Classified document',
            'secrecy' => 'top_secret',
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/saraban/documents')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertFalse($ids->contains($classified->id));
    }

    public function test_attachment_download_requires_authorization(): void
    {
        Storage::fake('private_uploads');
        Storage::disk('private_uploads')->put('saraban/attachments/secret.pdf', 'pdf-bytes');

        $document = $this->makeDocument([
            'secrecy' => 'secret',
            'allow_download' => true,
            'created_by' => $this->registrar->id,
        ]);

        $attachment = DocumentAttachment::create([
            'document_id' => $document->id,
            'name' => 'secret.pdf',
            'type' => 'file',
            'path' => 'saraban/attachments/secret.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->registrar->id,
        ]);

        $this->actingAs($this->outsider)
            ->get("/api/saraban/attachments/{$attachment->id}/download")
            ->assertForbidden();

        $this->actingAs($this->registrar)
            ->get("/api/saraban/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    public function test_download_is_blocked_when_allow_download_is_off(): void
    {
        Storage::fake('private_uploads');
        Storage::disk('private_uploads')->put('saraban/attachments/open.pdf', 'pdf-bytes');

        $document = $this->makeDocument([
            'allow_download' => false,
            'created_by' => $this->staff->id,
        ]);

        $attachment = DocumentAttachment::create([
            'document_id' => $document->id,
            'name' => 'open.pdf',
            'type' => 'file',
            'path' => 'saraban/attachments/open.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->get("/api/saraban/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }
}
