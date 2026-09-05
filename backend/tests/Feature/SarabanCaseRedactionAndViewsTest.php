<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentCase;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\Redaction;
use App\Models\Saraban\SavedView;
use App\Models\User;
use App\Services\Saraban\RedactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-18 — เปรียบเทียบ สารบบเรื่อง การปกปิด และมุมมองที่บันทึกไว้ (SoT §22)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **ตัวตนของหนังสือห้ามเปลี่ยน** — จัดกลุ่ม/ปกปิดแล้วทะเบียนต้องเหมือนเดิม
 *   2. **สำเนาที่ปกปิดแยกจากต้นฉบับ** — ต้นฉบับไม่ถูกแตะและยังเปิดได้
 *   3. **การปกปิดต้องลบข้อความจริง** ไม่ใช่แค่วาดกล่องทับ
 *   4. **สารบบไม่เป็นช่องค้นพบหนังสือ** — กรองด้วย policy รายฉบับ
 *   5. **มุมมองที่แบ่งปันไม่ได้แบ่งปันข้อมูล** และแก้ได้เฉพาะเจ้าของ
 */
class SarabanCaseRedactionAndViewsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const SECRET_TEXT = 'ThisIsTheSecretPassage';

    private OrgUnit $unit;

    private OrgUnit $otherUnit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = $this->orgUnit('UNIT-CASE', 'ฝ่ายอำนวยการ');
        $this->otherUnit = $this->orgUnit('UNIT-CASE-OTHER', 'ฝ่ายวิชาการ');

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    private function orgUnit(string $code, string $name): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'name' => $name,
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions, ?int $orgUnitId = null): User
    {
        $user = User::factory()->create([
            'org_unit_id' => $orgUnitId ?? $this->unit->id,
            'password' => Hash::make(self::PASSWORD),
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill(['last_authenticated_at' => now()])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    /** PDF ที่มีข้อความจริงอยู่ข้างใน — จำเป็นสำหรับพิสูจน์ว่าการปกปิดลบข้อความ */
    private function pdfWithText(): string
    {
        $stream = 'BT /F1 24 Tf 72 700 Td ('.self::SECRET_TEXT.') Tj ET';

        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>endobj',
            '4 0 obj<</Length '.strlen($stream).'>>stream'."\n".$stream."\nendstream endobj",
            '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    }

    private function createDocument(?User $author = null): Document
    {
        $author ??= $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'เรื่องทดสอบสารบบ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->pdfWithText()),
        ])->assertCreated()->json();

        return Document::findOrFail($created['id']);
    }

    // ── สารบบเรื่อง (SoT §22) ───────────────────────────────────────────────

    /** ข้อบังคับหลักของ SoT §22 — จัดกลุ่มแล้วตัวตนของหนังสือต้องไม่เปลี่ยน */
    public function test_grouping_a_document_into_a_case_never_changes_the_document(): void
    {
        $document = $this->createDocument();
        $before = $document->only(['subject', 'status', 'direction', 'document_number_id', 'owner_org_unit_id']);

        $manager = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($manager);

        $case = $this->postJson('/api/saraban/cases', [
            'code' => 'CASE-001',
            'title' => 'เรื่องร้องเรียนการจัดซื้อ',
        ])->assertCreated()->json('case');

        $this->postJson("/api/saraban/cases/{$case['id']}/documents", [
            'document_id' => $document->id,
            'note' => 'หนังสือฉบับแรกของเรื่อง',
        ])->assertCreated();

        $this->assertSame($before, $document->fresh()->only(array_keys($before)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.case.document_attached']);
    }

    public function test_a_document_can_be_detached_without_a_trace_on_the_document(): void
    {
        $document = $this->createDocument();
        $manager = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($manager);

        $case = $this->postJson('/api/saraban/cases', ['code' => 'CASE-002', 'title' => 'เรื่องทดสอบ'])
            ->assertCreated()->json('case');

        $this->postJson("/api/saraban/cases/{$case['id']}/documents", ['document_id' => $document->id])->assertCreated();
        $this->deleteJson("/api/saraban/cases/{$case['id']}/documents/{$document->id}")->assertOk();

        $this->assertSame(0, DocumentCase::query()->findOrFail($case['id'])->documents()->count());
        $this->assertNotNull($document->fresh());
    }

    /** สารบบต้องไม่กลายเป็นช่องค้นพบว่ามีหนังสือฉบับใดในระบบบ้าง */
    public function test_a_case_hides_documents_the_reader_cannot_see(): void
    {
        $document = $this->createDocument();
        $manager = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($manager);

        $case = $this->postJson('/api/saraban/cases', ['code' => 'CASE-003', 'title' => 'เรื่องทดสอบ'])
            ->assertCreated()->json('case');
        $this->postJson("/api/saraban/cases/{$case['id']}/documents", ['document_id' => $document->id])->assertCreated();

        // ผู้อ่านจากอีกหน่วยงานที่ไม่ได้สร้างหนังสือฉบับนี้
        $this->actAs($this->userWith(['saraban.document.view'], $this->otherUnit->id));

        $body = $this->getJson("/api/saraban/cases/{$case['id']}")->assertOk()->json();

        $this->assertCount(0, $body['documents']);
        $this->assertSame(1, $body['hidden_count']);
    }

    public function test_a_closed_case_accepts_no_new_documents(): void
    {
        $document = $this->createDocument();
        $manager = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($manager);

        $case = $this->postJson('/api/saraban/cases', ['code' => 'CASE-004', 'title' => 'เรื่องทดสอบ'])
            ->assertCreated()->json('case');
        $this->postJson("/api/saraban/cases/{$case['id']}/close")->assertOk();

        $this->postJson("/api/saraban/cases/{$case['id']}/documents", ['document_id' => $document->id])
            ->assertStatus(422);
    }

    public function test_attaching_a_document_you_cannot_see_is_refused(): void
    {
        $document = $this->createDocument();

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.document.create'], $this->otherUnit->id));

        $case = $this->postJson('/api/saraban/cases', ['code' => 'CASE-005', 'title' => 'เรื่องทดสอบ'])
            ->assertCreated()->json('case');

        $this->postJson("/api/saraban/cases/{$case['id']}/documents", ['document_id' => $document->id])
            ->assertForbidden();
    }

    /**
     * **สารบบของหน่วยงานอื่นปิดไม่ได้** (SoT §6, §22)
     *
     * ที่มา: การตรวจทานเมื่อ 2026-09-05 พบว่า `close()` ตรวจแค่ด่าน
     * `ability:saraban.document.create` ระดับความสามารถ — ผู้ที่สร้างหนังสือได้
     * ในหน่วยงานตน จึงปิดสารบบของหน่วยงานอื่นได้ · ตรึงด้วย `DocumentCasePolicy`
     */
    public function test_a_case_owned_by_another_unit_cannot_be_closed(): void
    {
        $owner = $this->userWith(['saraban.document.view', 'saraban.document.create'], $this->otherUnit->id);

        $case = DocumentCase::create([
            'code' => 'CASE-SCOPE-1',
            'title' => 'เรื่องของหน่วยงานอื่น',
            'owner_org_unit_id' => $this->otherUnit->id,
            'created_by' => $owner->id,
        ]);

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.document.create']));

        $this->postJson("/api/saraban/cases/{$case->id}/close")->assertForbidden();

        $this->assertSame(DocumentCase::STATUS_OPEN, $case->fresh()->status);
    }

    /** ถอดหนังสือออกจากสารบบของหน่วยงานอื่นไม่ได้เช่นกัน */
    public function test_a_case_owned_by_another_unit_cannot_be_changed(): void
    {
        $document = $this->createDocument();
        $owner = $this->userWith(['saraban.document.view', 'saraban.document.create'], $this->otherUnit->id);

        $case = DocumentCase::create([
            'code' => 'CASE-SCOPE-2',
            'title' => 'เรื่องของหน่วยงานอื่น',
            'owner_org_unit_id' => $this->otherUnit->id,
            'created_by' => $owner->id,
        ]);
        $case->documents()->attach($document->id, ['added_by' => $owner->id]);

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.document.create']));

        $this->deleteJson("/api/saraban/cases/{$case->id}/documents/{$document->id}")->assertForbidden();

        $this->assertSame(1, $case->fresh()->documents()->count());
    }

    /** เจ้าของเรื่องยังปิดสารบบของตัวเองได้ — ด่านใหม่ต้องไม่ปิดงานปกติ */
    public function test_the_creator_can_still_close_their_own_case(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create'], $this->otherUnit->id);
        $this->actAs($author);

        $case = $this->postJson('/api/saraban/cases', [
            'code' => 'CASE-SCOPE-3',
            'title' => 'เรื่องของตัวเอง',
            'owner_org_unit_id' => $this->otherUnit->id,
        ])->assertCreated()->json('case');

        $this->postJson("/api/saraban/cases/{$case['id']}/close")->assertOk();
    }

    // ── การปกปิดข้อมูล (SoT §22) ────────────────────────────────────────────

    /**
     * **ข้อพิสูจน์ที่สำคัญที่สุดของเช็คพอยต์นี้**
     *
     * การวาดกล่องทับข้อความไม่ใช่การปกปิด — ข้อความยังคัดลอกออกมาได้
     * เทสต์นี้ค้นหาข้อความลับในไฟล์ผลลัพธ์โดยตรง ถ้ายังเจอแปลว่าล้มเหลว
     */
    public function test_a_redacted_copy_no_longer_contains_the_hidden_text(): void
    {
        if (! app(RedactionService::class)->available()) {
            $this->markTestSkipped('ต้องติดตั้ง ghostscript ในภาพ container ก่อน (ดู backend/Dockerfile)');
        }

        $document = $this->createDocument();
        $version = $document->currentVersion;

        // ต้นฉบับมีข้อความลับอยู่จริง — ยืนยันก่อนว่าเทสต์วัดสิ่งที่ถูกต้อง
        $original = Storage::disk('private_uploads')->get($version->pdf_file_path);
        $this->assertStringContainsString(self::SECRET_TEXT, $original);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);

        $redaction = $this->postJson("/api/saraban/documents/{$document->id}/redactions", [
            'source_version_id' => $version->id,
            'reason' => 'ปกปิดข้อมูลส่วนบุคคลก่อนเปิดเผยตามคำขอ',
            'areas' => [['page' => 1, 'x' => 60, 'y' => 120, 'width' => 300, 'height' => 40]],
        ])->assertCreated()->json('redaction');

        $redacted = Storage::disk('private_uploads')->get($redaction['pdf_file_path']);

        $this->assertStringNotContainsString(self::SECRET_TEXT, $redacted);
    }

    /** ต้นฉบับต้องไม่ถูกแตะเลย */
    public function test_redacting_leaves_the_original_untouched(): void
    {
        if (! app(RedactionService::class)->available()) {
            $this->markTestSkipped('ต้องติดตั้ง ghostscript ก่อน');
        }

        $document = $this->createDocument();
        $version = $document->currentVersion;
        $originalHash = $version->file_hash;

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.signature.sign']));

        $this->postJson("/api/saraban/documents/{$document->id}/redactions", [
            'source_version_id' => $version->id,
            'reason' => 'ปกปิดข้อมูลส่วนบุคคลก่อนเปิดเผย',
            'areas' => [['page' => 1, 'x' => 60, 'y' => 120, 'width' => 300, 'height' => 40]],
        ])->assertCreated();

        $version->refresh();
        $this->assertSame($originalHash, $version->file_hash);
        Storage::disk('private_uploads')->assertExists($version->pdf_file_path);
        $this->assertSame(1, $document->fresh()->versions()->count());
    }

    public function test_a_redaction_records_its_reason_and_hash_in_the_audit_log(): void
    {
        if (! app(RedactionService::class)->available()) {
            $this->markTestSkipped('ต้องติดตั้ง ghostscript ก่อน');
        }

        $document = $this->createDocument();
        $this->actAs($this->userWith(['saraban.document.view', 'saraban.signature.sign']));

        $this->postJson("/api/saraban/documents/{$document->id}/redactions", [
            'source_version_id' => $document->currentVersion->id,
            'reason' => 'ปกปิดเลขประจำตัวประชาชนของผู้ร้อง',
            'areas' => [['page' => 1, 'x' => 60, 'y' => 120, 'width' => 300, 'height' => 40]],
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.document.redacted']);
        $this->assertNotNull(Redaction::query()->firstOrFail()->file_hash);
    }

    /** เหตุผลบังคับ — การปกปิดโดยไม่ระบุเหตุผลตรวจสอบย้อนหลังไม่ได้ */
    public function test_a_redaction_without_a_reason_is_refused(): void
    {
        $document = $this->createDocument();
        $this->actAs($this->userWith(['saraban.document.view', 'saraban.signature.sign']));

        $this->postJson("/api/saraban/documents/{$document->id}/redactions", [
            'source_version_id' => $document->currentVersion->id,
            'areas' => [['page' => 1, 'x' => 60, 'y' => 120, 'width' => 300, 'height' => 40]],
        ])->assertStatus(422);
    }

    /** เวอร์ชันของหนังสือฉบับอื่นต้องใช้เป็นต้นทางไม่ได้ */
    public function test_a_version_from_another_document_cannot_be_redacted(): void
    {
        $mine = $this->createDocument();
        $theirs = $this->createDocument();

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.signature.sign']));

        $this->postJson("/api/saraban/documents/{$mine->id}/redactions", [
            'source_version_id' => $theirs->currentVersion->id,
            'reason' => 'ปกปิดข้อมูลส่วนบุคคล',
            'areas' => [['page' => 1, 'x' => 60, 'y' => 120, 'width' => 300, 'height' => 40]],
        ])->assertStatus(422);
    }

    /** สำเนาที่ปกปิดแล้วแก้ไม่ได้ — สิ่งที่ส่งออกไปกับที่บันทึกไว้ต้องตรงกัน */
    public function test_a_redaction_record_cannot_be_edited(): void
    {
        $redaction = Redaction::create([
            'document_id' => $this->createDocument()->id,
            'source_version_id' => DocumentVersion::query()->firstOrFail()->id,
            'reason' => 'ทดสอบ',
            'areas' => [],
            'pdf_file_path' => 'saraban/redactions/test.pdf',
            'file_hash' => str_repeat('a', 128),
            'created_by' => $this->userWith([])->id,
        ]);

        $this->expectException(RuntimeException::class);
        $redaction->forceFill(['reason' => 'เปลี่ยนเหตุผลย้อนหลัง'])->save();
    }

    // ── เปรียบเทียบเอกสาร (SoT §22) ─────────────────────────────────────────

    public function test_comparing_two_versions_reports_hash_size_and_pages(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create', 'saraban.document.update']);
        $document = $this->createDocument($author);
        $first = $document->currentVersion;

        $this->actAs($author);
        $this->post("/api/saraban/documents/{$document->id}", [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'เรื่องทดสอบสารบบ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห2.pdf', $this->pdfWithText()."\n% changed"),
        ])->assertOk();

        $second = $document->fresh()->currentVersion;

        $result = $this->getJson("/api/saraban/documents/{$document->id}/compare?left={$first->id}&right={$second->id}")
            ->assertOk()
            ->json();

        $this->assertFalse($result['identical_file']);
        $this->assertSame($first->file_hash, $result['file']['left_hash']);
        $this->assertNotNull($result['file']['byte_delta']);
        $this->assertSame(1, $result['file']['left_pages']);
    }

    public function test_comparing_a_version_with_itself_is_refused(): void
    {
        $document = $this->createDocument();
        $version = $document->currentVersion;

        $this->getJson("/api/saraban/documents/{$document->id}/compare?left={$version->id}&right={$version->id}")
            ->assertStatus(422);
    }

    public function test_comparing_a_version_from_another_document_is_refused(): void
    {
        $mine = $this->createDocument();
        $theirs = $this->createDocument();

        $this->getJson(
            "/api/saraban/documents/{$mine->id}/compare?left={$mine->currentVersion->id}&right={$theirs->currentVersion->id}",
        )->assertStatus(422);
    }

    // ── มุมมองที่บันทึกไว้ (SoT §22) ────────────────────────────────────────

    public function test_a_saved_view_stores_the_query_not_the_result(): void
    {
        $this->actAs($this->userWith([]));

        $view = $this->postJson('/api/saraban/saved-views', [
            'name' => 'หนังสือด่วนที่รอฉัน',
            'resource' => SavedView::RESOURCE_DOCUMENTS,
            'filters' => ['urgency' => 'urgent', 'status' => 'submitted'],
        ])->assertCreated()->json('view');

        $this->assertSame(['urgency' => 'urgent', 'status' => 'submitted'], $view['filters']);
        $this->assertArrayNotHasKey('documents', $view);
    }

    public function test_a_private_view_is_invisible_to_everyone_else(): void
    {
        $owner = $this->userWith([]);
        $this->actAs($owner);

        $this->postJson('/api/saraban/saved-views', [
            'name' => 'ส่วนตัว',
            'resource' => SavedView::RESOURCE_DOCUMENTS,
            'filters' => ['status' => 'draft'],
        ])->assertCreated();

        $this->actAs($this->userWith([]));

        $this->assertSame([], $this->getJson('/api/saraban/saved-views')->assertOk()->json('views'));
    }

    public function test_a_shared_view_is_visible_but_only_the_owner_can_change_it(): void
    {
        $owner = $this->userWith([]);
        $this->actAs($owner);

        $view = $this->postJson('/api/saraban/saved-views', [
            'name' => 'คิวงานของฝ่าย',
            'resource' => SavedView::RESOURCE_INBOX,
            'filters' => ['pending' => true],
            'is_shared' => true,
        ])->assertCreated()->json('view');

        $this->actAs($this->userWith([]));

        $listed = $this->getJson('/api/saraban/saved-views')->assertOk()->json('views');
        $this->assertCount(1, $listed);
        $this->assertFalse($listed[0]['is_mine']);

        // เห็นได้ แต่แก้ไม่ได้ — 404 เพื่อไม่ยืนยันว่ามีอยู่ผ่านช่องทางการแก้
        $this->putJson("/api/saraban/saved-views/{$view['id']}", ['name' => 'แก้ชื่อคนอื่น'])->assertNotFound();
        $this->deleteJson("/api/saraban/saved-views/{$view['id']}")->assertNotFound();
    }

    public function test_an_unknown_resource_is_refused(): void
    {
        $this->actAs($this->userWith([]));

        $this->postJson('/api/saraban/saved-views', [
            'name' => 'ผิดชนิด',
            'resource' => 'made-up',
            'filters' => [],
        ])->assertStatus(422);
    }
}
