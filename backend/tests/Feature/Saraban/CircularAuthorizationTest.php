<?php

namespace Tests\Feature\Saraban;

use App\Models\Saraban\DocumentCircular;
use App\Models\Saraban\DocumentCircularRecipient;

/**
 * SEC-026 — ขาสุดท้ายของสายงานสารบรรณ: การเวียนหนังสือ
 *
 * เดิม `POST /documents/{id}/circular` รับ user_ids มาเท่าไรก็สร้างผู้รับเท่านั้น
 * โดยไม่ตรวจสิทธิ์ของผู้รับเลย และ `/circulars/incoming` ส่งเนื้อหนังสือกลับไป
 * ให้ผู้รับพร้อมกัน (eager load `circular.document`) การเวียนจึงเป็นทางลัด
 * ส่งหนังสือ — รวมถึงหนังสือชั้นความลับ — ไปให้ใครก็ได้ในระบบ
 */
class CircularAuthorizationTest extends SarabanTestCase
{
    public function test_a_document_can_be_circulated_to_someone_who_may_see_it(): void
    {
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);

        $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/circular", [
                'user_ids' => [$this->outsider->id],
                'message' => 'โปรดทราบ',
            ])
            ->assertOk();

        $this->assertSame(1, DocumentCircularRecipient::where('user_id', $this->outsider->id)->count());

        $this->assertDatabaseHas('saraban_audit_logs', [
            'document_id' => $document->id,
            'action' => 'circular.sent',
        ]);
    }

    /** ผู้รับหนังสือเวียนต้องเปิดอ่านหนังสือฉบับนั้นได้จริง ไม่ใช่ได้แต่การแจ้งเตือน */
    public function test_a_circular_recipient_may_open_the_document(): void
    {
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);

        $this->actingAs($this->outsider)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertForbidden();

        $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/circular", ['user_ids' => [$this->outsider->id]])
            ->assertOk();

        $this->actingAs($this->outsider)
            ->getJson("/api/saraban/documents/{$document->id}")
            ->assertOk();
    }

    public function test_a_classified_document_cannot_be_circulated_to_someone_without_clearance(): void
    {
        $document = $this->makeDocument([
            'secrecy' => 'secret',
            'created_by' => $this->registrar->id,
        ]);

        // `staff` มี saraban.document.view แต่ไม่มี view_classified
        $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/circular", ['user_ids' => [$this->staff->id]])
            ->assertStatus(422);

        $this->assertSame(0, DocumentCircular::count());
        $this->assertSame(0, DocumentCircularRecipient::count());
    }

    /** การเวียนไม่ควรเป็นทางลัดให้คนที่ไม่มีสิทธิ์ในโมดูลสารบรรณเลยเห็นหนังสือ */
    public function test_a_user_without_the_saraban_view_permission_cannot_be_a_recipient(): void
    {
        $outsiderWithoutSaraban = $this->makeUser([]);
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);

        $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/circular", [
                'user_ids' => [$outsiderWithoutSaraban->id],
            ])
            ->assertStatus(422);

        $this->assertSame(0, DocumentCircularRecipient::count());
    }

    /** ผู้รับรายเดียวที่ไม่ผ่านต้องทำให้ทั้งคำขอตกไป ไม่ใช่ส่งบางส่วนเงียบ ๆ */
    public function test_one_ineligible_recipient_blocks_the_whole_request(): void
    {
        $document = $this->makeDocument(['secrecy' => 'secret', 'created_by' => $this->registrar->id]);

        $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/circular", [
                'user_ids' => [$this->approver->id, $this->staff->id],
            ])
            ->assertStatus(422);

        $this->assertSame(0, DocumentCircularRecipient::count());
    }

    public function test_a_user_who_cannot_see_the_document_cannot_circulate_it(): void
    {
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);

        $this->actingAs($this->outsider)
            ->postJson("/api/saraban/documents/{$document->id}/circular", ['user_ids' => [$this->staff->id]])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->getJson("/api/saraban/documents/{$document->id}/circulars/tracking")
            ->assertForbidden();
    }

    public function test_only_the_named_recipient_can_acknowledge_a_circular(): void
    {
        $document = $this->makeDocument(['created_by' => $this->registrar->id]);

        $this->actingAs($this->registrar)
            ->postJson("/api/saraban/documents/{$document->id}/circular", ['user_ids' => [$this->outsider->id]])
            ->assertOk();

        $circular = DocumentCircular::firstOrFail();

        $this->actingAs($this->staff)
            ->postJson("/api/saraban/circulars/{$circular->id}/acknowledge")
            ->assertNotFound();

        $this->actingAs($this->outsider)
            ->postJson("/api/saraban/circulars/{$circular->id}/acknowledge")
            ->assertOk();

        $this->assertTrue(
            DocumentCircularRecipient::where('circular_id', $circular->id)
                ->where('user_id', $this->outsider->id)
                ->value('is_acknowledged') == true,
        );
    }
}
