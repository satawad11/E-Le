<?php

namespace Tests\Legacy\Feature\Saraban;

use App\Models\Setting;

class SignatureTest extends SarabanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Password confirmation is on by default; these tests exercise it explicitly.
        Setting::updateOrCreate(['key' => 'require_pin_for_signature'], ['value' => '1']);
    }

    public function test_signing_with_esignature_mode_records_the_mode(): void
    {
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'esignature',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'hash']);

        $this->assertDatabaseHas('saraban_document_signatures', [
            'document_id' => $document->id,
            'signer_id' => $this->approver->id,
            'mode' => 'esignature',
        ]);

        $this->assertDatabaseHas('saraban_documents', [
            'id' => $document->id,
            'status' => 'signed',
        ]);
    }

    public function test_signing_in_draw_mode_does_not_error(): void
    {
        // Regression: SignatureController used Str::random() without importing Str,
        // which made every draw-mode signature a 500.
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'draw',
                'password' => 'password',
                'signature_image' => 'data:image/png;base64,'.base64_encode('fake-png'),
            ])
            ->assertOk();

        $this->assertDatabaseHas('saraban_document_signatures', [
            'document_id' => $document->id,
            'mode' => 'draw',
        ]);
    }

    public function test_signing_in_text_mode_does_not_error(): void
    {
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'text',
                'password' => 'password',
                'text' => 'ลงนามแทน',
            ])
            ->assertOk();

        $this->assertDatabaseHas('saraban_document_signatures', [
            'document_id' => $document->id,
            'mode' => 'text',
        ]);
    }

    public function test_esignature_mode_rejects_a_wrong_password(): void
    {
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'esignature',
                'password' => 'not-the-password',
            ])
            ->assertStatus(400);

        $this->assertDatabaseMissing('saraban_document_signatures', [
            'document_id' => $document->id,
        ]);
    }

    public function test_signing_requires_a_password_when_the_setting_row_is_missing(): void
    {
        // Regression: an absent setting used to mean "no password required".
        Setting::where('key', 'require_pin_for_signature')->delete();

        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", ['mode' => 'esignature'])
            ->assertStatus(400);
    }

    public function test_signing_requires_a_password_even_when_legacy_setting_is_disabled(): void
    {
        Setting::where('key', 'require_pin_for_signature')->update(['value' => '0']);
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", ['mode' => 'draw'])
            ->assertStatus(400);
    }

    public function test_signer_must_be_assigned_to_the_document(): void
    {
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);
        $document->receivers()->detach($this->approver->id);

        $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'esignature',
                'password' => 'password',
            ])
            ->assertForbidden();
    }

    public function test_verify_detects_document_content_tampering(): void
    {
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);
        $hash = $this->actingAs($this->approver)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'esignature',
                'password' => 'password',
            ])->json('hash');

        $document->forceFill(['title' => 'Tampered title'])->saveQuietly();

        $this->getJson("/api/saraban/verify/{$hash}")
            ->assertOk()
            ->assertJsonPath('is_valid', false);
    }

    public function test_user_without_sign_permission_cannot_sign(): void
    {
        $document = $this->makeDocument(['status' => 'approved_pending_signature']);

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'esignature',
                'password' => 'password',
            ])
            ->assertForbidden();
    }

    public function test_public_verify_hides_the_details_of_classified_documents(): void
    {
        $document = $this->makeDocument([
            'status' => 'approved_pending_signature',
            'secrecy' => 'secret',
            'title' => 'ความลับราชการ',
        ]);
        $document->receivers()->syncWithoutDetaching([$this->registrar->id]);

        $hash = $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/sign", [
                'mode' => 'esignature',
                'password' => 'password',
            ])
            ->json('hash');

        $response = $this->getJson("/api/saraban/verify/{$hash}")->assertOk();

        $response->assertJsonPath('document.classified', true);
        $response->assertJsonMissing(['title' => 'ความลับราชการ']);
        $response->assertJsonPath('is_valid', true);
    }
}
