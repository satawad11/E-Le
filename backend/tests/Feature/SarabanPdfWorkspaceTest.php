<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Services\Saraban\SarabanPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-10 — PDF Workspace (SoT §14)
 *
 * เรื่องที่ตรึงไว้ เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **Final PDF ต้องเรนเดอร์ที่ฝั่ง server** — ไม่มี endpoint ใดรับภาพจาก
 *      เบราว์เซอร์ ภาพหน้าจอจึงไม่มีทางกลายเป็นหนังสือราชการ
 *   2. **พิกัดเป็น PDF point ไม่ใช่พิกัดหน้าจอ** — วางที่เครื่องหนึ่งแล้วต้อง
 *      ออกมาตำแหน่งเดียวกันทุกเครื่อง
 *   3. **การเรนเดอร์สร้างเวอร์ชันใหม่ ไม่เขียนทับต้นฉบับ**
 *   4. **หนังสือที่ลงนามแล้วเติมตราไม่ได้**
 */
class SarabanPdfWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-PDF',
            'name' => 'ฝ่ายสารบรรณ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    /** @param array<int,string> $permissions */
    private function officer(array $permissions = []): User
    {
        $user = User::factory()->create(['org_unit_id' => $this->unit->id]);

        foreach (array_merge([
            'saraban.document.view', 'saraban.document.create',
            'saraban.document.update', 'saraban.incoming.stamp',
        ], $permissions) as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    /**
     * PDF ขนาดเล็กที่ถูกต้องตามรูปแบบ เพื่อให้ FPDI อ่านได้จริง
     *
     * ใช้ไฟล์จริงแทน stub เพราะประเด็นทั้งหมดของเช็คพอยต์นี้คือการเรนเดอร์
     * ลงบนไฟล์จริง — ทดสอบด้วยไฟล์ปลอมจะพิสูจน์อะไรไม่ได้เลย
     */
    private function realPdf(): string
    {
        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<<>>>>endobj',
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

        $pdf .= 'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function documentWithFile(User $officer): Document
    {
        $created = $this->actingAs($officer)
            ->post('/api/saraban/documents', [
                'document_type_id' => $this->type->id,
                'direction' => Document::DIRECTION_INTERNAL,
                'subject' => 'หนังสือสำหรับประทับตรา',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unit->id,
                'file' => UploadedFile::fake()->createWithContent('หนังสือ.pdf', $this->realPdf()),
            ])
            ->assertCreated()
            ->json();

        return Document::findOrFail($created['id']);
    }

    // ── พิกัดเป็น PDF point ─────────────────────────────────────────────────

    /**
     * พิกัดที่บันทึกต้องเป็นค่าเดียวกับที่ส่งมา ไม่ถูกแปลงหรือปัดทิ้ง
     *
     * ถ้าเก็บเป็นพิกัดหน้าจอหรือเปอร์เซ็นต์ ตราที่วางบนเครื่องจอใหญ่จะไปโผล่
     * คนละที่บนเครื่องจอเล็ก — หนังสือราชการฉบับเดียวกันออกมาไม่เหมือนกัน
     */
    public function test_coordinates_are_stored_as_pdf_points_exactly_as_sent(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        $this->actingAs($officer)
            ->putJson("/api/saraban/documents/{$document->id}/annotations", [
                'annotations' => [[
                    'page' => 1,
                    'type' => 'stamp',
                    'x' => 402.75,
                    'y' => 58.5,
                    'width' => 150,
                    'height' => 60,
                    'payload' => ['title' => 'รับหนังสือ', 'register_number' => '0001/2569'],
                ]],
            ])
            ->assertOk();

        $annotation = DocumentAnnotation::query()->firstOrFail();

        $this->assertSame(402.75, $annotation->x);
        $this->assertSame(58.5, $annotation->y);
        $this->assertSame(1, $annotation->page);
        $this->assertSame('0001/2569', $annotation->payload['register_number']);
    }

    public function test_saving_replaces_the_whole_set_so_removed_objects_really_disappear(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);
        $url = "/api/saraban/documents/{$document->id}/annotations";

        $this->actingAs($officer)->putJson($url, ['annotations' => [
            ['page' => 1, 'type' => 'stamp', 'x' => 10, 'y' => 10],
            ['page' => 1, 'type' => 'text', 'x' => 20, 'y' => 20, 'payload' => ['text' => 'ลบทีหลัง']],
        ]])->assertOk();

        $this->assertSame(2, DocumentAnnotation::query()->count());

        $this->actingAs($officer)->putJson($url, ['annotations' => [
            ['page' => 1, 'type' => 'stamp', 'x' => 10, 'y' => 10],
        ]])->assertOk();

        $this->assertSame(1, DocumentAnnotation::query()->count());
    }

    // ── เรนเดอร์ที่ฝั่ง server (ข้อบังคับหลักของ SoT §14) ───────────────────

    /**
     * ข้อที่สำคัญที่สุดของเช็คพอยต์นี้
     *
     * ไม่มี endpoint ใดของ workspace รับไฟล์ภาพหรือ data URI เลย — ผู้ใช้ส่งได้
     * เฉพาะพิกัดกับข้อความ ภาพหน้าจอจากเบราว์เซอร์จึงไม่มีทางกลายเป็นหนังสือราชการ
     */
    public function test_the_workspace_never_accepts_an_image_from_the_browser(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        $response = $this->actingAs($officer)
            ->putJson("/api/saraban/documents/{$document->id}/annotations", [
                'annotations' => [[
                    'page' => 1,
                    'type' => 'stamp',
                    'x' => 10,
                    'y' => 10,
                    // พยายามแนบภาพหน้าจอมาเป็นเนื้อหา
                    'payload' => ['image' => 'data:image/png;base64,iVBORw0KGgo='],
                ]],
            ])
            ->assertOk();

        $annotation = DocumentAnnotation::query()->firstOrFail();

        /*
         * payload เก็บสิ่งที่ส่งมาได้ก็จริง แต่ตัวเรนเดอร์ **ไม่อ่านคีย์ `image` เลย**
         * — พิสูจน์ที่ซอร์สว่าไม่มีเส้นทางใดนำภาพจากผู้ใช้ไปวางบน PDF
         */
        $renderer = file_get_contents(app_path('Services/Saraban/SarabanPdfRenderer.php'));

        $this->assertStringNotContainsString("'image'", $renderer);
        $this->assertStringNotContainsString('base64', $renderer);
        $this->assertStringNotContainsString('Image(', $renderer, 'ตัวเรนเดอร์ต้องไม่วางภาพจากผู้ใช้ลงบน PDF');
        $this->assertArrayHasKey('image', $annotation->payload);
    }

    public function test_rendering_produces_a_real_pdf_as_a_new_version_without_touching_the_original(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);
        $original = $document->currentVersion;

        $this->actingAs($officer)
            ->putJson("/api/saraban/documents/{$document->id}/annotations", [
                'annotations' => [[
                    'page' => 1, 'type' => 'stamp', 'x' => 400, 'y' => 50,
                    'width' => 150, 'height' => 60,
                    'payload' => ['title' => 'รับหนังสือ', 'register_number' => '0007/2569', 'received_date' => '04/09/2569'],
                ]],
            ])
            ->assertOk();

        $rendered = $this->actingAs($officer)
            ->postJson("/api/saraban/documents/{$document->id}/annotations/render")
            ->assertCreated()
            ->json('version');

        // เวอร์ชันใหม่ ไม่ใช่การเขียนทับ
        $this->assertSame(2, $rendered['version_no']);
        $this->assertNotSame($original->pdf_file_path, $rendered['file_path']);

        // ต้นฉบับยังอยู่ครบและเนื้อหาไม่เปลี่ยน
        $original->refresh();
        Storage::disk('private_uploads')->assertExists($original->pdf_file_path);
        $this->assertSame(
            hash('sha256', Storage::disk('private_uploads')->get($original->pdf_file_path)),
            $original->file_hash,
        );

        // ไฟล์ที่ได้เป็น PDF จริง ไม่ใช่ภาพ
        $contents = Storage::disk('private_uploads')->get($rendered['file_path']);
        $this->assertStringStartsWith('%PDF', $contents);
        $this->assertGreaterThan(strlen($this->realPdf()), strlen($contents), 'ไฟล์ที่ประทับตราแล้วต้องมีเนื้อหาเพิ่มขึ้น');
    }

    public function test_the_renderer_can_be_called_directly_and_returns_pdf_bytes(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        DocumentAnnotation::create([
            'document_version_id' => $document->currentVersion->id,
            'page' => 1,
            'type' => DocumentAnnotation::TYPE_TEXT,
            'x' => 100,
            'y' => 100,
            'payload' => ['text' => 'ข้อความภาษาไทยบนหนังสือ', 'font_size' => 16],
            'created_by' => $officer->id,
        ]);

        $bytes = app(SarabanPdfRenderer::class)->render($document->currentVersion);

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    // ── ตราประทับรับเติมจากทะเบียนรับ (SoT §10) ─────────────────────────────

    public function test_the_receive_stamp_is_filled_from_the_incoming_register_not_typed_by_hand(): void
    {
        $officer = $this->officer(['saraban.incoming.register']);

        $registration = $this->actingAs($officer)
            ->post('/api/saraban/incoming', [
                'document_type_id' => $this->type->id,
                'subject' => 'หนังสือรับที่ต้องประทับตรา',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unit->id,
                'sender_name' => 'สำนักงานเขต',
                'source_number' => 'ศธ 04002/99',
                'file' => UploadedFile::fake()->createWithContent('รับ.pdf', $this->realPdf()),
            ])
            ->assertCreated()
            ->json();

        $stamp = $this->actingAs($officer)
            ->getJson("/api/saraban/documents/{$registration['document_id']}/annotations")
            ->assertOk()
            ->json('receive_stamp');

        $this->assertSame('รับหนังสือ', $stamp['title']);
        $this->assertNotSame('', $stamp['register_number'], 'เลขรับต้องมาจากทะเบียนรับ');
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $stamp['received_time']);
    }

    // ── สิทธิ์และการปิดหลังลงนาม ────────────────────────────────────────────

    public function test_placing_stamps_requires_the_stamp_permission(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        $viewer = User::factory()->create(['org_unit_id' => $this->unit->id]);
        $viewer->givePermissionTo(Permission::findOrCreate('saraban.document.view', 'web'));

        $this->actingAs($viewer->fresh())
            ->putJson("/api/saraban/documents/{$document->id}/annotations", ['annotations' => []])
            ->assertForbidden();
    }

    public function test_a_signed_document_cannot_be_stamped_any_further(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        $document->update(['status' => Document::STATUS_SIGNED]);

        // ถูกปฏิเสธที่ policy (403) ไม่ใช่ที่ตรรกะใน controller — เป็นด่านที่ถูกกว่า
        $this->actingAs($officer)
            ->putJson("/api/saraban/documents/{$document->id}/annotations", [
                'annotations' => [['page' => 1, 'type' => 'stamp', 'x' => 10, 'y' => 10]],
            ])
            ->assertForbidden();

        $this->assertSame(0, DocumentAnnotation::query()->count());
    }

    /**
     * หนังสือรับที่ลงทะเบียนแล้วต้องประทับตรารับได้ — เป็นงานหลักของงานสารบรรณ
     *
     * เคสนี้เผยบั๊กจริงระหว่างทำ INT-10: เดิมใช้ `authorize('update')` ซึ่ง
     * อนุญาตเฉพาะฉบับร่าง หนังสือรับ (สถานะ `registered`) จึงประทับตราไม่ได้เลย
     * ทั้งที่เป็นเหตุผลหลักที่มี PDF Workspace
     */
    public function test_a_registered_incoming_document_can_still_be_stamped(): void
    {
        $officer = $this->officer(['saraban.incoming.register']);

        $registration = $this->actingAs($officer)
            ->post('/api/saraban/incoming', [
                'document_type_id' => $this->type->id,
                'subject' => 'หนังสือรับที่ต้องประทับตรา',
                'confidentiality' => 'normal',
                'urgency' => 'normal',
                'owner_org_unit_id' => $this->unit->id,
                'sender_name' => 'สำนักงานเขต',
                'source_number' => 'ศธ 04002/55',
                'file' => UploadedFile::fake()->createWithContent('รับ.pdf', $this->realPdf()),
            ])
            ->assertCreated()
            ->json();

        $document = Document::findOrFail($registration['document_id']);
        $this->assertSame(Document::STATUS_REGISTERED, $document->status);

        $this->actingAs($officer)
            ->putJson("/api/saraban/documents/{$document->id}/annotations", [
                'annotations' => [[
                    'page' => 1, 'type' => 'stamp', 'x' => 400, 'y' => 40,
                    'width' => 150, 'height' => 60,
                    'payload' => ['title' => 'รับหนังสือ', 'register_number' => '0001/2569'],
                ]],
            ])
            ->assertOk();

        $this->actingAs($officer)
            ->postJson("/api/saraban/documents/{$document->id}/annotations/render")
            ->assertCreated();

        $this->assertSame(2, $document->fresh()->versions()->count());
    }

    public function test_an_outsider_cannot_read_the_workspace_of_another_units_document(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        $otherUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-FAR-PDF',
            'name' => 'ฝ่ายไกล',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $stranger = User::factory()->create(['org_unit_id' => $otherUnit->id]);
        $stranger->givePermissionTo(Permission::findOrCreate('saraban.document.view', 'web'));

        $this->actingAs($stranger->fresh())
            ->getJson("/api/saraban/documents/{$document->id}/annotations")
            ->assertForbidden();
    }

    public function test_stamping_is_recorded_in_the_central_audit_log(): void
    {
        $officer = $this->officer();
        $document = $this->documentWithFile($officer);

        $this->actingAs($officer)
            ->putJson("/api/saraban/documents/{$document->id}/annotations", [
                'annotations' => [['page' => 1, 'type' => 'stamp', 'x' => 10, 'y' => 10]],
            ])
            ->assertOk();

        $this->actingAs($officer)
            ->postJson("/api/saraban/documents/{$document->id}/annotations/render")
            ->assertCreated();

        foreach (['saraban.document.annotated', 'saraban.document.stamped'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'resource_id' => (string) $document->id,
                'actor_id' => $officer->id,
            ]);
        }
    }
}
