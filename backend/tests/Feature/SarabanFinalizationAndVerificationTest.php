<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\Saraban\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-12 — ออกหนังสือฉบับสมบูรณ์ QR และการตรวจสอบจากภายนอก (SoT §8, §12)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **ฉบับสมบูรณ์เป็นเวอร์ชันใหม่** — ฉบับที่ลงนามและต้นฉบับยังอยู่ครบ
 *   2. **QR วาดเป็นเวกเตอร์ ไม่ใช่ภาพ** — รักษาข้อบังคับเดียวกับ INT-10/INT-11
 *   3. **หน้าตรวจสอบเปิดสาธารณะแต่เปิดเผยเท่าที่จำเป็น**
 *   4. **แก้ข้อมูลหลังออกหนังสือแล้วตรวจพบได้**
 */
class SarabanFinalizationAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private OrgUnit $unit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-FINAL',
            'name' => 'ฝ่ายอำนวยการ',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL],
        );

        NumberSeries::query()->firstOrCreate(
            ['code' => 'internal-final'],
            ['name' => 'หนังสือภายใน', 'category' => DocumentType::CATEGORY_INTERNAL, 'prefix' => 'ศธ 04001'],
        );
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create([
            'org_unit_id' => $this->unit->id,
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

        return $pdf.'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    }

    /** หนังสือที่เดินครบจนลงนามแล้ว พร้อมออกฉบับสมบูรณ์ */
    private function signedDocument(): array
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'ขออนุมัติดำเนินโครงการ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        $document = Document::findOrFail($created['id']);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", [
            'page' => 1, 'x' => 380, 'y' => 700, 'width' => 150, 'height' => 50,
            'strokes' => [[['x' => 0, 'y' => 10], ['x' => 30, 'y' => 25]]],
        ])->assertCreated();

        $document->update(['status' => Document::STATUS_SIGNED]);

        return [$document->fresh(), $signer];
    }

    // ── ออกฉบับสมบูรณ์ (SoT §8) ─────────────────────────────────────────────

    public function test_finalizing_creates_a_new_final_version_and_keeps_the_signed_one(): void
    {
        [$document, $signer] = $this->signedDocument();

        $signedVersion = $document->currentVersion;
        $signedHash = $signedVersion->file_hash;

        $this->actAs($signer);

        $response = $this->postJson("/api/saraban/documents/{$document->id}/finalize")
            ->assertCreated()
            ->json();

        $this->assertSame(DocumentVersion::STAGE_FINAL, $response['version']['stage']);
        $this->assertNotNull($response['verification_hash']);

        // ฉบับที่ลงนามยังอยู่และไม่ถูกแตะ
        $signedVersion->refresh();
        $this->assertSame($signedHash, $signedVersion->file_hash);
        Storage::disk('private_uploads')->assertExists($signedVersion->pdf_file_path);

        // ฉบับสมบูรณ์เป็นเวอร์ชันใหม่และเป็นฉบับปัจจุบัน
        $document->refresh();
        $this->assertSame($response['version']['id'], $document->current_version_id);
        $this->assertNotNull($document->finalized_at);
        $this->assertNotNull($document->content_hash);
    }

    /**
     * QR ต้องเป็นเวกเตอร์ ไม่ใช่ภาพ — เช่นเดียวกับลายเซ็นและลายมือ (SoT §14)
     */
    /**
     * INT-16 — ตำแหน่ง QR มาจากนโยบายกลาง ไม่ใช่ค่าที่ฝังในโค้ด (ปิด RISK-32)
     *
     * เป็นหลักฐานว่าศูนย์นโยบายมีผลกับพฤติกรรมจริง ไม่ใช่ตารางที่ไม่มีใครอ่าน
     */
    public function test_the_qr_placement_follows_the_published_layout_policy(): void
    {
        [$document, $signer] = $this->signedDocument();

        $manager = $this->userWith(['saraban.document.view', 'saraban.policy.manage']);
        $this->actAs($manager);

        $this->postJson('/api/saraban/policies/document_layout/draft', [
            'payload' => ['qr' => ['page' => 1, 'x' => 120, 'y' => 60, 'size' => 90]],
        ])->assertCreated();
        $this->postJson('/api/saraban/policies/document_layout/publish')->assertOk();

        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $qr = DocumentAnnotation::query()->where('type', DocumentAnnotation::TYPE_QR)->firstOrFail();

        $this->assertEqualsWithDelta(120, $qr->x, 0.01);
        $this->assertEqualsWithDelta(60, $qr->y, 0.01);
        $this->assertEqualsWithDelta(90, $qr->width, 0.01);
    }

    public function test_the_qr_code_is_drawn_as_vectors_not_embedded_as_an_image(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);

        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $qr = DocumentAnnotation::query()->where('type', DocumentAnnotation::TYPE_QR)->firstOrFail();

        $this->assertSame($document->fresh()->verification_hash, $qr->payload['hash']);
        $this->assertArrayNotHasKey('image', $qr->payload);

        // ตัวเรนเดอร์ต้องไม่มีเส้นทางวางภาพเลย
        $renderer = file_get_contents(app_path('Services/Saraban/SarabanPdfRenderer.php'));
        $this->assertStringNotContainsString('Image(', $renderer);
        $this->assertStringNotContainsString('base64', $renderer);

        // ไฟล์ที่ได้เป็น PDF จริงและใหญ่ขึ้นเพราะมีช่อง QR เพิ่มเข้ามา
        $final = $document->fresh()->currentVersion;
        $contents = Storage::disk('private_uploads')->get($final->pdf_file_path);
        $this->assertStringStartsWith('%PDF', $contents);
    }

    public function test_a_document_cannot_be_finalized_twice(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);

        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $this->postJson("/api/saraban/documents/{$document->id}/finalize")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ไปแล้ว'));
    }

    public function test_an_unsigned_document_cannot_be_finalized(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create', 'saraban.signature.sign']);
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'ยังไม่ลงนาม',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ห.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        $this->postJson("/api/saraban/documents/{$created['id']}/finalize")->assertForbidden();
    }

    /** การออกฉบับสมบูรณ์เป็นการกระทำที่ย้อนกลับไม่ได้ จึงต้องยืนยันตัวตนซ้ำ (SoT §24) */
    public function test_finalizing_requires_recent_re_authentication(): void
    {
        [$document, $signer] = $this->signedDocument();

        $this->app['auth']->forgetGuards();
        $token = $signer->createToken('stale');
        $token->accessToken->forceFill(['last_authenticated_at' => now()->subHour()])->save();
        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);

        $this->postJson("/api/saraban/documents/{$document->id}/finalize")
            ->assertStatus(423)
            ->assertJsonPath('code', 'reauthentication_required');
    }

    // ── การตรวจสอบจากภายนอก (SoT §12) ──────────────────────────────────────

    public function test_anyone_holding_the_paper_can_verify_it_without_signing_in(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $hash = $document->fresh()->verification_hash;

        // ไม่ส่ง header ยืนยันตัวตนเลย
        $this->withHeaders([]);
        $this->app['auth']->forgetGuards();

        $result = $this->getJson("/api/saraban/verify/{$hash}")->assertOk()->json();

        $this->assertTrue($result['is_valid']);
        $this->assertFalse($result['content_altered']);
        $this->assertSame('ขออนุมัติดำเนินโครงการ', $result['subject']);
        $this->assertNotEmpty($result['signatures']);
        $this->assertSame($signer->name, $result['signatures'][0]['name']);
    }

    /**
     * หน้าตรวจสอบเปิดสาธารณะ จึงต้องเปิดเผยเท่าที่จำเป็น
     *
     * ผู้เปิดคือคนทั่วไปที่ถือกระดาษอยู่ในมือ ไม่ใช่ผู้ใช้ที่ผ่านการยืนยันตัวตน
     */
    public function test_the_public_result_does_not_leak_internal_information(): void
    {
        [$document, $signer] = $this->signedDocument();
        $document->update(['summary' => 'สาระสำคัญที่เป็นข้อมูลภายใน']);

        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $hash = $document->fresh()->verification_hash;
        $this->withHeaders([]);
        $this->app['auth']->forgetGuards();

        $result = $this->getJson("/api/saraban/verify/{$hash}")->assertOk()->json();

        foreach (['summary', 'versions', 'annotations', 'created_by', 'file_path'] as $leaked) {
            $this->assertArrayNotHasKey($leaked, $result, "ห้ามเปิดเผย {$leaked} บนหน้าตรวจสอบสาธารณะ");
        }
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->getJson('/api/saraban/verify/'.Str::random(40))->assertNotFound();
    }

    /**
     * แก้ข้อมูลหลังออกหนังสือแล้วต้องตรวจพบได้ (SoT §12)
     */
    public function test_altering_the_document_after_issue_is_reported_on_the_verification_page(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $hash = $document->fresh()->verification_hash;

        // แก้เรื่องโดยตรงในฐานข้อมูล เลี่ยงทุกด่านของแอป
        DB::table('saraban_documents')
            ->where('id', $document->id)
            ->update(['subject' => 'เรื่องที่ถูกแก้ย้อนหลัง']);

        $this->withHeaders([]);
        $this->app['auth']->forgetGuards();

        $result = $this->getJson("/api/saraban/verify/{$hash}")->assertOk()->json();

        $this->assertTrue($result['content_altered'], 'ต้องตรวจพบว่าข้อมูลถูกแก้');
        $this->assertFalse($result['is_valid'], 'เอกสารที่ถูกแก้ต้องไม่ถือว่าใช้อ้างอิงได้');
    }

    public function test_the_qr_image_endpoint_returns_an_svg(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $hash = $document->fresh()->verification_hash;
        $this->withHeaders([]);
        $this->app['auth']->forgetGuards();

        $response = $this->get("/api/saraban/verify/{$hash}/qrcode")->assertOk();

        $this->assertStringContainsString('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    /** รหัสตรวจสอบต้องเดาไม่ได้ — เป็นทั้งตัวชี้เอกสารและตัวคุมการเข้าถึง */
    public function test_the_verification_code_is_long_and_random(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $hash = $document->fresh()->verification_hash;

        $this->assertSame(40, strlen($hash));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $hash);
    }

    public function test_the_verification_url_points_at_the_public_page(): void
    {
        $url = app(DocumentVerificationService::class)->verificationUrl('abc123');

        $this->assertStringContainsString('/verify-saraban/abc123', $url);
    }

    public function test_finalizing_is_written_to_the_central_audit_log(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize")->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.document.finalized',
            'resource_id' => (string) $document->id,
            'actor_id' => $signer->id,
        ]);
    }
}
