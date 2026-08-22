<?php

namespace Tests\Feature\Saraban;

use App\Models\Saraban\AuditLog;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentUserState;

/**
 * SEC-025 — IDOR ของ `POST /documents/{id}/read` และ `POST /documents/{id}/state`
 *
 * สอง endpoint นี้เคยผ่านเพียง `permission:saraban.document.view` ระดับ route
 * แล้วเขียนแถวสถานะจาก id ที่ผู้ใช้ส่งมาตรง ๆ โดยไม่ตรวจว่า
 *   - หนังสือนั้นมีอยู่จริงหรือไม่
 *   - ผู้ใช้มีสิทธิ์เห็นหนังสือนั้นหรือไม่
 *   - หนังสือนั้นเป็นหนังสือลับหรือไม่
 */
class DocumentStateAuthorizationTest extends SarabanTestCase
{
    public function test_an_uninvolved_user_cannot_mark_someone_elses_document_as_read(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->outsider)
            ->postJson("/api/saraban/documents/{$document->id}/read")
            ->assertForbidden();

        $this->assertDatabaseMissing('saraban_document_user_states', [
            'document_id' => $document->id,
            'user_id' => $this->outsider->id,
        ]);
    }

    public function test_an_uninvolved_user_cannot_pin_or_hide_someone_elses_document(): void
    {
        $document = $this->makeDocument();

        $this->actingAs($this->outsider)
            ->postJson("/api/saraban/documents/{$document->id}/state", ['is_pinned' => true])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->postJson("/api/saraban/documents/{$document->id}/state", ['is_hidden' => true])
            ->assertForbidden();

        $this->assertDatabaseMissing('saraban_document_user_states', [
            'document_id' => $document->id,
            'user_id' => $this->outsider->id,
        ]);
    }

    /**
     * เดิม endpoint นี้รับ id ดิบ แถวสถานะจึงถูกสร้างชี้ไปยังหนังสือที่ไม่มีอยู่จริงได้
     */
    public function test_an_unknown_document_id_answers_404_and_writes_nothing(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents/999999/state', ['is_pinned' => true])
            ->assertNotFound();

        $this->actingAs($this->staff)
            ->postJson('/api/saraban/documents/999999/read')
            ->assertNotFound();

        $this->assertSame(0, DocumentUserState::count());
    }

    /**
     * ผู้ที่ไม่ได้รับอนุญาตให้เห็นหนังสือลับต้องถูกปฏิเสธ ไม่ใช่แค่ "มองไม่เห็นในรายการ"
     */
    public function test_a_classified_document_is_refused_on_the_state_route_too(): void
    {
        $document = $this->makeDocument(['secrecy' => 'secret', 'created_by' => $this->registrar->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/state", ['is_pinned' => true])
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/read")
            ->assertForbidden();

        $this->assertSame(0, DocumentUserState::where('user_id', $this->staff->id)->count());
    }

    public function test_the_owner_can_still_read_pin_and_hide_their_own_document(): void
    {
        $document = $this->makeDocument(['created_by' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/read")
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/state", ['is_pinned' => true])
            ->assertOk();

        $this->assertDatabaseHas('saraban_document_user_states', [
            'document_id' => $document->id,
            'user_id' => $this->staff->id,
            'is_read' => true,
            'is_pinned' => true,
        ]);
    }

    /** AUDIT-007 — การอ่านครั้งแรกและการซ่อนหนังสือต้องตามรอยได้ */
    public function test_reading_and_hiding_are_written_to_the_audit_log(): void
    {
        $document = $this->makeDocument(['created_by' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/read")
            ->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/documents/{$document->id}/state", ['is_hidden' => true])
            ->assertOk();

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'user_id' => $this->staff->id,
            'action' => 'document.read',
        ]);

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'user_id' => $this->staff->id,
            'action' => 'document.state.hidden',
        ]);
    }

    /** การเปิดหน้าเดิมซ้ำ ๆ ต้องไม่ทำให้ audit log ท่วม */
    public function test_reading_the_same_document_twice_is_audited_once(): void
    {
        $document = $this->makeDocument(['created_by' => $this->staff->id]);

        foreach (range(1, 3) as $_) {
            $this->actingAs($this->staff)
                ->postJson("/api/saraban/documents/{$document->id}/read")
                ->assertOk();
        }

        $this->assertSame(1, AuditLog::where('document_id', $document->id)
            ->where('action', 'document.read')
            ->count());
    }

    public function test_a_receiver_can_read_a_document_addressed_to_them(): void
    {
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);
        $document->receivers()->syncWithoutDetaching([$this->outsider->id]);

        $this->actingAs($this->outsider)
            ->postJson("/api/saraban/documents/{$document->id}/read")
            ->assertOk();

        $this->assertNotNull(
            Document::find($document->id)->receivers()->where('users.id', $this->outsider->id)->first()->pivot->read_at,
        );
    }
}
