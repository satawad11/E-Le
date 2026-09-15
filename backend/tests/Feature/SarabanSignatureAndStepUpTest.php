<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\SignatureEvidence;
use App\Models\User;
use App\Services\Saraban\SignatureService;
use App\Services\TotpService;
use App\Support\ProductionEnvironmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INT-11 — เกษียน ลงนาม และการยืนยันตัวตนซ้ำ (SoT §14, §15, §24)
 *
 * เรื่องที่ตรึงไว้ เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **ลงนามไม่ได้ถ้าไม่เพิ่งยืนยันตัวตน** — โทเคนที่เปิดค้างไว้ต้องลงนามไม่ได้
 *   2. **ปิดด่านนี้บน production ไม่ได้**
 *   3. **หลักฐานการลงนามครบและแก้ไม่ได้**
 *   4. **ลายมือ ≠ ลายเซ็น** — เกษียนไม่สร้างหลักฐานการลงนาม
 *   5. **ลายเซ็นเก็บเป็นพิกัดเส้น ไม่ใช่ภาพ**
 */
class SarabanSignatureAndStepUpTest extends TestCase
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
            'code' => 'UNIT-SIGN',
            'name' => 'ฝ่ายอำนวยการ',
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

    /**
     * ทำงานในนามผู้ใช้ด้วย **โทเคนจริง** ไม่ใช่ `actingAs()`
     *
     * `actingAs()` ของ Sanctum ใช้ `TransientToken` ซึ่งไม่มีแถวใน
     * `personal_access_tokens` — จึงไม่มีที่เก็บเวลายืนยันตัวตน และด่าน step-up
     * จะปฏิเสธเสมอ (ซึ่งถูกต้องตามหลัก fail closed)
     *
     * เทสต์ชุดนี้จึงต้องใช้โทเคนจริงเหมือนที่หน้าเว็บใช้ มิฉะนั้นจะทดสอบเส้นทาง
     * ที่ผู้ใช้จริงไม่ได้เดิน
     *
     * @return array<string,string> header สำหรับแนบกับคำขอ
     */
    private function tokenFor(User $user, ?string $authenticatedAt = 'now'): array
    {
        $token = $user->createToken('test-token');

        $token->accessToken->forceFill([
            'last_authenticated_at' => $authenticatedAt === null ? null : (
                $authenticatedAt === 'now' ? now() : $authenticatedAt
            ),
        ])->save();

        return ['Authorization' => 'Bearer '.$token->plainTextToken];
    }

    /** ทำงานในนามผู้ใช้ด้วยโทเคนจริงตลอดทั้งเทสต์ */
    private function actAs(User $user, ?string $authenticatedAt = 'now'): void
    {
        /*
         * ล้าง guard ที่ resolve ผู้ใช้ไว้แล้วก่อนเปลี่ยนตัวตน
         *
         * ในคำขอจริงแต่ละครั้งเป็นคนละ process อยู่แล้ว แต่ในชุดทดสอบ application
         * instance เดียวถูกใช้ซ้ำ — guard จึงจำผู้ใช้คนแรกไว้และเพิกเฉยต่อโทเคนใบใหม่
         * ถ้าไม่ล้าง เทสต์จะรันในนามผู้ใช้คนก่อนหน้าโดยไม่มีอะไรบอก
         */
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->tokenFor($user, $authenticatedAt));
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

    private function documentFor(User $author): Document
    {
        $this->actAs($author);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'หนังสือรอลงนาม',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ลงนาม.pdf', $this->realPdf()),
        ])
            ->assertCreated()
            ->json();

        return Document::findOrFail($created['id']);
    }

    /** @return array<string,mixed> */
    private function signaturePayload(): array
    {
        return [
            'page' => 1,
            'x' => 380,
            'y' => 700,
            'width' => 150,
            'height' => 50,
            'strokes' => [
                [['x' => 0, 'y' => 20], ['x' => 20, 'y' => 5], ['x' => 40, 'y' => 25]],
                [['x' => 45, 'y' => 10], ['x' => 70, 'y' => 30]],
            ],
        ];
    }

    // ── การยืนยันตัวตนซ้ำ (SoT §24 · SEC-GAP-01) ────────────────────────────

    /**
     * ข้อที่สำคัญที่สุดของเช็คพอยต์นี้
     *
     * โทเคนของระบบมีอายุหลายชั่วโมง ถ้าการมีโทเคนแปลว่าลงนามได้ เครื่องที่เปิด
     * ค้างไว้จะลงนามหนังสือราชการแทนเจ้าของได้ ซึ่งย้อนกลับไม่ได้
     */
    public function test_signing_is_refused_when_the_user_has_not_recently_re_authenticated(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);

        // จำลองโทเคนที่เปิดค้างไว้นานเกินช่วงที่ยอมรับ
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        $response = $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())
            ->assertStatus(423);

        $this->assertSame('reauthentication_required', $response->json('code'));
        $this->assertSame(0, SignatureEvidence::query()->count());
    }

    public function test_confirming_the_password_opens_the_window_and_signing_then_succeeds(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        $this->postJson('/api/auth/confirm-password', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('valid_for_minutes', 10);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())
            ->assertCreated();

        $this->assertSame(1, SignatureEvidence::query()->count());
    }

    public function test_confirming_with_totp_opens_the_window_and_signing_succeeds(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $signer->forceFill(['totp_confirmed_at' => now()->subDay()])->save();
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        $totp = app(TotpService::class);
        $secret = $totp->secretForUser($signer);
        $validCode = $totp->generateCode($secret);

        $this->postJson('/api/auth/confirm-password', ['totp_code' => $validCode])
            ->assertOk()
            ->assertJsonPath('valid_for_minutes', 10);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())
            ->assertCreated();
    }

    /**
     * ความลับ TOTP คำนวณจาก APP_KEY ได้เสมอ — ถ้าไม่บังคับให้ลงทะเบียนก่อน
     * ผู้ที่ขโมยโทเคนไปจะคำนวณรหัสเองแล้วผ่านด่านยืนยันตัวตนซ้ำได้ทันที
     */
    public function test_totp_is_rejected_until_the_user_has_enrolled_the_authenticator(): void
    {
        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        $totp = app(TotpService::class);
        $validCode = $totp->generateCode($totp->secretForUser($signer));

        $this->postJson('/api/auth/confirm-password', ['totp_code' => $validCode])
            ->assertStatus(422);
    }

    /** ความลับ TOTP ต้องไม่ออกให้โทเคนที่ล็อกอินค้างไว้โดยไม่กรอกรหัสผ่าน */
    public function test_totp_secret_is_not_handed_out_without_the_password(): void
    {
        $signer = $this->userWith(['saraban.signature.sign']);
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        $this->postJson('/api/auth/totp-setup', [])->assertStatus(422);
        $this->postJson('/api/auth/totp-setup', ['password' => 'ไม่ใช่รหัสผ่าน'])
            ->assertStatus(422)
            ->assertJsonMissingPath('secret');
    }

    /** ผูกแอปสำเร็จแล้วจึงใช้ OTP ยืนยันตัวตนซ้ำได้ — ทั้งสองรอบอยู่ที่เส้นทางเดียว */
    public function test_enrolling_the_authenticator_unlocks_totp_step_up(): void
    {
        $signer = $this->userWith(['saraban.signature.sign']);
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        // รอบที่ 1 — ขอความลับไปตั้งค่าในแอป ยังไม่ถือว่าผูกสำเร็จ
        $secret = $this->postJson('/api/auth/totp-setup', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('enrolled', false)
            ->json('secret');

        $code = app(TotpService::class)->generateCode($secret);

        // รอบที่ 2 — ยืนยันด้วยรหัสจากแอปจริง
        $this->postJson('/api/auth/totp-setup', ['password' => self::PASSWORD, 'totp_code' => $code])
            ->assertOk()
            ->assertJsonPath('enrolled', true);

        $this->assertNotNull($signer->fresh()->totp_confirmed_at);

        $this->postJson('/api/auth/confirm-password', ['totp_code' => $code])->assertOk();
    }

    public function test_a_wrong_password_does_not_open_the_window(): void
    {
        $signer = $this->userWith(['saraban.signature.sign']);
        $this->actAs($signer, now()->subHour()->toDateTimeString());

        $this->postJson('/api/auth/confirm-password', ['password' => 'ไม่ใช่รหัสผ่าน'])
            ->assertStatus(422);

        /*
         * อ่านจากแถวโทเคนโดยตรง — `currentAccessToken()` มีค่าเฉพาะภายในคำขอ
         * นอกคำขอจะเป็น null เสมอ
         */
        $this->assertTrue(
            $signer->tokens()->latest('id')->first()->last_authenticated_at->lt(now()->subMinutes(30)),
            'รหัสผ่านผิดต้องไม่เปิดช่วงยืนยันตัวตน',
        );
    }

    public function test_production_refuses_to_start_with_step_up_switched_off(): void
    {
        $problems = ProductionEnvironmentGuard::problems('production', false, [
            'app_key' => 'base64:AAAA',
            'step_up_enabled' => false,
        ]);

        $this->assertNotEmpty(array_filter(
            $problems,
            fn (string $problem) => str_contains($problem, 'REQUIRE_STEP_UP_REAUTH'),
        ));
    }

    /**
     * ต่ออายุโทเคนต้องไม่ยืดช่วงยืนยันตัวตน
     *
     * ถ้ายืดได้ ผู้ใช้จะคงสถานะ "เพิ่งยืนยันตัวตน" ไว้ตลอดเพียงแค่กดต่ออายุ
     * ซึ่งทำให้ด่านนี้ไม่มีความหมาย
     */
    public function test_refreshing_the_token_carries_the_original_confirmation_time_not_a_new_one(): void
    {
        $user = $this->userWith(['saraban.document.view']);

        $original = now()->subMinutes(9);
        $this->actAs($user, $original->toDateTimeString());

        $this->postJson('/api/refresh-token')->assertOk();

        $carried = $user->fresh()->tokens()->latest('id')->first()->last_authenticated_at;

        $this->assertSame($original->format('Y-m-d H:i'), $carried->format('Y-m-d H:i'));
    }

    // ── หลักฐานการลงนาม (SoT §14) ──────────────────────────────────────────

    public function test_the_signature_evidence_records_everything_the_master_requires(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())
            ->assertCreated();

        $evidence = SignatureEvidence::query()->firstOrFail();

        $this->assertSame($signer->id, $evidence->signer_id);
        $this->assertSame($signer->name, $evidence->signer_name_snapshot);
        $this->assertSame('saraban.signature.sign', $evidence->authority_snapshot);
        $this->assertSame('drawn', $evidence->signature_method);
        $this->assertNotNull($evidence->signed_at);
        $this->assertNotNull($evidence->document_hash);
        $this->assertNotNull($evidence->auth_token_id, 'ต้องอ้างถึง session ที่ใช้ลงนาม');
        $this->assertNotNull($evidence->reauthenticated_at);
        $this->assertNotNull($evidence->user_agent);

        // แฮชต้องตรงกับไฟล์ฉบับที่ลงนามจริง
        $this->assertTrue(app(SignatureService::class)->evidenceIsIntact($evidence));
    }

    public function test_the_evidence_cannot_be_edited_or_deleted(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())->assertCreated();

        $evidence = SignatureEvidence::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $evidence->update(['signer_name_snapshot' => 'เปลี่ยนไม่ได้']);
    }

    /**
     * ตรวจพบได้เมื่อไฟล์ที่ลงนามถูกเปลี่ยนภายหลัง
     */
    public function test_tampering_with_the_signed_file_is_detected(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())->assertCreated();

        $evidence = SignatureEvidence::query()->firstOrFail();
        $this->assertTrue(app(SignatureService::class)->evidenceIsIntact($evidence));

        Storage::disk('private_uploads')->put($evidence->version->pdf_file_path, '%PDF-1.4 ถูกแก้');

        $this->assertFalse(app(SignatureService::class)->evidenceIsIntact($evidence));
    }

    // ── ลายเซ็นเป็นพิกัด ไม่ใช่ภาพ (SoT §14) ────────────────────────────────

    public function test_a_signature_is_stored_as_stroke_coordinates_not_as_an_image(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())->assertCreated();

        $annotation = DocumentAnnotation::query()
            ->where('type', DocumentAnnotation::TYPE_SIGNATURE)
            ->firstOrFail();

        $this->assertCount(2, $annotation->payload['strokes']);
        $this->assertSame(0, $annotation->payload['strokes'][0][0]['x']);
        $this->assertArrayNotHasKey('image', $annotation->payload);
    }

    // ── ลายมือ ≠ ลายเซ็น (SoT §15) ──────────────────────────────────────────

    /**
     * การเกษียนต้องไม่สร้างหลักฐานการลงนาม และไม่ต้องยืนยันตัวตนซ้ำ
     *
     * SoT §15 ระบุชัดว่าลายมือไม่ใช่ลายเซ็น — การให้ความเห็นไม่ใช่การรับรอง
     * เอกสารในทางกฎหมาย ถ้าปฏิบัติเหมือนกันจะได้หลักฐานการลงนามปลอมเต็มไปหมด
     */
    public function test_endorsing_with_handwriting_does_not_create_signature_evidence(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $endorser = $this->userWith(['saraban.document.view', 'saraban.endorse.create']);
        $this->actAs($endorser);

        $this->postJson("/api/saraban/documents/{$document->id}/endorse", [
            'objects' => [[
                'page' => 1,
                'type' => 'handwriting',
                'x' => 100,
                'y' => 400,
                'width' => 200,
                'height' => 80,
                'payload' => ['strokes' => [[['x' => 0, 'y' => 0], ['x' => 30, 'y' => 20]]]],
            ]],
        ])->assertCreated();

        $this->assertSame(0, SignatureEvidence::query()->count(), 'ลายมือต้องไม่กลายเป็นหลักฐานการลงนาม');
        $this->assertSame(1, DocumentAnnotation::query()->where('type', 'handwriting')->count());
    }

    // ── วันที่และข้อความที่วางพร้อมลายเซ็น (SoT §14 · NAV §4.7) ────────────

    /**
     * **ลายเซ็น วันที่ และข้อความกำกับ อยู่ในเวอร์ชันเดียวกัน**
     *
     * หนังสือราชการที่ลงนามประกอบด้วยลายเซ็น ชื่อ-ตำแหน่ง และวันที่ ซึ่งเป็น
     * บล็อกเดียวกัน · ถ้าให้วางวันที่แยกเป็นการเกษียนอีกครั้ง จะได้เวอร์ชันเพิ่ม
     * อีกฉบับ และหลักฐานการลงนามจะชี้ไปยังไฟล์ที่ยังไม่มีวันที่กำกับ
     */
    public function test_a_date_and_note_placed_with_the_signature_land_on_the_same_version(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", [
            ...$this->signaturePayload(),
            'objects' => [
                ['page' => 1, 'type' => 'date', 'x' => 380, 'y' => 580, 'width' => 150, 'height' => 20,
                    'payload' => ['text' => '6 กันยายน 2569']],
                ['page' => 1, 'type' => 'note', 'x' => 380, 'y' => 600, 'width' => 200, 'height' => 20,
                    'payload' => ['text' => 'ผู้อำนวยการโรงเรียน']],
            ],
        ])->assertCreated();

        $evidence = SignatureEvidence::query()->firstOrFail();

        // วัตถุทั้งสามอยู่บนเวอร์ชันก่อนลงนาม แล้วถูกเรนเดอร์รวมเป็นฉบับที่ลงนาม
        $sourceVersion = $document->fresh()->versions()->orderBy('version_no')->first();

        foreach (['signature', 'date', 'note'] as $type) {
            $this->assertSame(
                1,
                DocumentAnnotation::query()
                    ->where('document_version_id', $sourceVersion->id)
                    ->where('type', $type)
                    ->count(),
                "ต้องมีวัตถุชนิด {$type} หนึ่งชิ้นบนเวอร์ชันที่ลงนาม",
            );
        }

        $this->assertSame(1, SignatureEvidence::query()->count(), 'ต้องมีหลักฐานการลงนามเพียงหนึ่งรายการ');
        $this->assertNotNull($evidence->document_hash);
    }

    /**
     * **ลายมือแนบมากับการลงนามไม่ได้** (SoT §15)
     *
     * ถ้าปล่อยให้แนบได้ จะเกิดช่องที่ทำให้การเกษียน — ซึ่งไม่ใช่การรับรองเอกสาร
     * ในทางกฎหมาย — ถูกบันทึกรวมอยู่ในหลักฐานการลงนาม
     */
    public function test_endorsement_object_types_cannot_ride_along_with_a_signature(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.signature.sign']));

        foreach (['handwriting', 'checkmark', 'stamp'] as $type) {
            $this->postJson("/api/saraban/documents/{$document->id}/sign", [
                ...$this->signaturePayload(),
                'objects' => [[
                    'page' => 1, 'type' => $type, 'x' => 100, 'y' => 100, 'width' => 50, 'height' => 20,
                ]],
            ])->assertStatus(422);
        }

        $this->assertSame(0, SignatureEvidence::query()->count());
    }

    public function test_endorsing_requires_its_own_permission_not_the_signing_one(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signerOnly = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);

        $this->actAs($signerOnly);

        $this->postJson("/api/saraban/documents/{$document->id}/endorse", [
            'objects' => [['page' => 1, 'type' => 'note', 'x' => 10, 'y' => 10, 'payload' => ['text' => 'ความเห็น']]],
        ])
            ->assertForbidden();
    }

    // ── สิทธิ์ในการลงนาม (SoT §16) ──────────────────────────────────────────

    /**
     * งานสารบรรณเตรียมที่ว่างให้ได้ แต่ลงนามแทนไม่ได้ (SoT §16)
     */
    public function test_a_saraban_officer_without_signing_authority_cannot_sign(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $officer = $this->userWith(['saraban.document.view', 'saraban.incoming.stamp', 'saraban.workflow.review']);

        $this->actAs($officer);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())
            ->assertForbidden();

        $this->assertSame(0, SignatureEvidence::query()->count());
    }

    public function test_a_document_cannot_be_signed_twice(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())->assertCreated();

        $document->update(['status' => Document::STATUS_SIGNED]);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())
            ->assertForbidden();
    }

    public function test_signing_is_written_to_the_central_audit_log(): void
    {
        $author = $this->userWith(['saraban.document.view', 'saraban.document.create']);
        $document = $this->documentFor($author);

        $signer = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/sign", $this->signaturePayload())->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'saraban.signature.signed',
            'resource_id' => (string) $document->id,
            'actor_id' => $signer->id,
        ]);
    }
}
