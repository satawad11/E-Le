<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\Policy;
use App\Models\Saraban\SignatureEvidence;
use App\Models\Saraban\SignatureRevocation;
use App\Models\User;
use App\Services\Saraban\PolicyCenter;
use App\Services\Saraban\SignatureVerificationService;
use App\Support\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * SIGNATURE-VERIFICATION — การตรวจสอบลายเซ็น ความน่าเชื่อถือ และการเพิกถอน
 * (SoT §14, §24, §26)
 *
 * เรื่องที่ตรึงไว้ เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **ไฟล์ที่ถูกแก้หลังลงนามต้องจับได้** — ทั้งหน้าจอภายในและหน้าตรวจสอบสาธารณะ
 *   2. **ห้าม VALID เพราะมีภาพลายเซ็น** — ต้องผ่านการผูกเวอร์ชัน แฮช ตัวตน อำนาจ
 *   3. **Electronic Signature ≠ Digital Signature** — ไม่มีใบรับรอง = ไม่รายงานผลใบรับรอง
 *   4. **เพิกถอนต้องมีเหตุผล + ผู้ตัดสินคนละคน + ยืนยันตัวตนซ้ำ**
 *   5. **หน้าตรวจสอบสาธารณะห้ามรั่วรายละเอียดภายใน**
 */
class SarabanSignatureVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private OrgUnit $unit;

    private OrgUnit $otherUnit;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->unit = $this->orgUnit('UNIT-VERIFY', 'ฝ่ายอำนวยการ');
        $this->otherUnit = $this->orgUnit('UNIT-VERIFY-OTHER', 'ฝ่ายวิชาการ');

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

    /** ใช้โทเคนจริง — `actingAs()` ของ Sanctum ไม่มีแถวให้บันทึกเวลายืนยันตัวตน */
    private function actAs(User $user, ?string $authenticatedAt = 'now'): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill([
            'last_authenticated_at' => $authenticatedAt === null ? null : now(),
        ])->save();

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

    private function signer(): User
    {
        return $this->userWith([
            'saraban.document.view', 'saraban.document.create', 'saraban.signature.sign',
        ]);
    }

    /** หนังสือที่ลงนามเรียบร้อยแล้วหนึ่งฉบับ พร้อมผู้ลงนาม */
    private function signedDocument(?User $signer = null): array
    {
        $signer ??= $this->signer();
        $this->actAs($signer);

        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => 'หนังสือรอตรวจสอบลายเซ็น',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->unit->id,
            'file' => UploadedFile::fake()->createWithContent('ลงนาม.pdf', $this->realPdf()),
        ])->assertCreated()->json();

        $document = Document::findOrFail($created['id']);

        $this->postJson("/api/saraban/documents/{$document->id}/sign", [
            'page' => 1, 'x' => 380, 'y' => 700, 'width' => 150, 'height' => 50,
            'strokes' => [[['x' => 0, 'y' => 20], ['x' => 20, 'y' => 5]]],
        ])->assertCreated();

        return [$document->fresh(), $signer];
    }

    private function verify(Document $document): array
    {
        return app(SignatureVerificationService::class)->verify($document->fresh());
    }

    // ── ผลตรวจที่ผ่าน ────────────────────────────────────────────────────────

    public function test_a_properly_signed_document_verifies_as_valid(): void
    {
        [$document] = $this->signedDocument();

        $result = $this->verify($document);

        $this->assertSame(SignatureVerificationService::VALID, $result['status']);
        $this->assertSame(1, $result['signature_count']);
        $this->assertSame(SignatureEvidence::TYPE_ELECTRONIC, $result['signatures'][0]['signature_type']);
    }

    /**
     * **ไม่มีใบรับรอง = ไม่รายงานผลใบรับรองเลย**
     *
     * ข้อบังคับตรงตัวของเช็คพอยต์นี้ — การเติมหัวข้อ "ใบรับรอง: ผ่าน" ให้ลายเซ็น
     * ที่ไม่มีใบรับรอง คือการอ้างสิ่งที่ระบบพิสูจน์ไม่ได้
     */
    public function test_an_electronic_signature_makes_no_certificate_claim(): void
    {
        [$document] = $this->signedDocument();

        $signature = $this->verify($document)['signatures'][0];

        $this->assertFalse($signature['has_certificate']);
        $this->assertFalse($signature['has_trusted_timestamp']);
        $this->assertSame(
            [],
            array_values(array_filter(
                $signature['checks'],
                fn (array $check) => in_array($check['check'], ['certificate', 'timestamp'], true),
            )),
            'ลายเซ็นที่ไม่มีใบรับรองต้องไม่มีหัวข้อผลตรวจใบรับรองหรือ timestamp เลย',
        );
    }

    // ── ความสมบูรณ์ของเอกสาร ────────────────────────────────────────────────

    /** **ข้อพิสูจน์ที่สำคัญที่สุดของเช็คพอยต์นี้** */
    public function test_a_document_modified_after_signing_is_detected(): void
    {
        [$document] = $this->signedDocument();

        $evidence = SignatureEvidence::query()->where('document_id', $document->id)->firstOrFail();
        Storage::disk(StoredFile::DISK)->put($evidence->version->pdf_file_path, 'เนื้อหาที่ถูกสับเปลี่ยน');

        $result = $this->verify($document);

        $this->assertSame(SignatureVerificationService::DOCUMENT_MODIFIED_AFTER_SIGNING, $result['status']);
    }

    public function test_a_missing_signed_file_cannot_be_reported_as_valid(): void
    {
        [$document] = $this->signedDocument();

        $evidence = SignatureEvidence::query()->where('document_id', $document->id)->firstOrFail();
        Storage::disk(StoredFile::DISK)->delete($evidence->version->pdf_file_path);

        $this->assertSame(SignatureVerificationService::INVALID, $this->verify($document)['status']);
    }

    /** หลักฐานที่ชี้ไปเวอร์ชันของหนังสือฉบับอื่น ต้องไม่ผ่าน */
    public function test_a_signature_bound_to_the_wrong_document_version_is_invalid(): void
    {
        [$mine] = $this->signedDocument();
        [$theirs] = $this->signedDocument();

        $evidence = SignatureEvidence::query()->where('document_id', $mine->id)->firstOrFail();
        $foreignVersion = DocumentVersion::query()->where('document_id', $theirs->id)->firstOrFail();

        // เขียนตรงผ่าน query builder เพราะโมเดลห้ามแก้หลักฐาน (ซึ่งถูกต้องแล้ว)
        DB::table('saraban_signature_evidences')
            ->where('id', $evidence->id)
            ->update(['document_version_id' => $foreignVersion->id]);

        $this->assertSame(SignatureVerificationService::INVALID, $this->verify($mine)['status']);
    }

    /** แฮชในหลักฐานไม่ตรงกับแฮชของเวอร์ชันที่ผูกไว้ = การผูกเสียหาย */
    public function test_a_hash_that_does_not_match_its_bound_version_is_invalid(): void
    {
        [$document] = $this->signedDocument();

        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update(['document_hash' => hash('sha256', 'แฮชที่ไม่เกี่ยวข้อง')]);

        $this->assertSame(SignatureVerificationService::INVALID, $this->verify($document)['status']);
    }

    // ── ตัวตนผู้ลงนาม ────────────────────────────────────────────────────────

    /**
     * ผู้สร้างเวอร์ชันที่ลงนาม ต้องเป็นผู้ลงนามคนเดียวกัน
     *
     * ปิดช่องที่ธุรการเรนเดอร์เวอร์ชันแล้วผูกหลักฐานเป็นชื่อผู้บริหาร ซึ่งเป็น
     * การลงนามแทนที่ SoT §16 ห้ามไว้
     */
    public function test_a_signature_credited_to_someone_who_did_not_produce_it_is_flagged(): void
    {
        [$document] = $this->signedDocument();
        $someoneElse = $this->userWith(['saraban.document.view']);

        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update(['signer_id' => $someoneElse->id]);

        $this->assertSame(SignatureVerificationService::SIGNER_MISMATCH, $this->verify($document)['status']);
    }

    // ── อำนาจลงนาม ──────────────────────────────────────────────────────────

    public function test_a_signature_without_recorded_authority_is_invalid(): void
    {
        [$document] = $this->signedDocument();

        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update(['authority_snapshot' => null]);

        $this->assertSame(SignatureVerificationService::AUTHORITY_INVALID, $this->verify($document)['status']);
    }

    /**
     * **อำนาจที่สิ้นสุดแล้วในวันนี้ ไม่ทำให้ลายเซ็นเดิมเสีย**
     *
     * หนังสือที่ลงนามถูกต้องเมื่อสองปีก่อน ต้องไม่กลายเป็นไม่ถูกต้องเพราะผู้ลงนาม
     * เกษียณไปแล้ว — แต่ผู้ตรวจต้องรู้ว่าอำนาจนั้นหมดไปแล้ว
     */
    public function test_authority_that_has_since_ended_is_a_warning_not_a_failure(): void
    {
        [$document, $signer] = $this->signedDocument();

        // ถอนสิทธิ์ออกหลังลงนามเสร็จ — เหมือนผู้ลงนามพ้นตำแหน่ง
        $signer->revokePermissionTo('saraban.signature.sign');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $result = $this->verify($document);

        $this->assertSame(SignatureVerificationService::VALID_WITH_WARNINGS, $result['status']);
    }

    /**
     * ผู้รับมอบอำนาจลงนามแทนได้ และผลตรวจต้องยอมรับ
     *
     * `AuthorityResolver` รวมการมอบอำนาจอยู่แล้ว — เทสต์นี้ตรึงว่าการตรวจย้อนหลัง
     * ใช้ตัวตัดสินตัวเดียวกัน ไม่ได้สร้างกติกาอำนาจของตัวเองขึ้นใหม่
     */
    public function test_a_delegate_signature_is_accepted_by_verification(): void
    {
        $delegator = $this->userWith(['saraban.document.view', 'saraban.signature.sign']);
        $delegate = $this->signer();

        DB::table('workflow_delegations')->insert([
            'uuid' => (string) Str::uuid(),
            'delegator_id' => $delegator->id,
            'delegate_id' => $delegate->id,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$document] = $this->signedDocument($delegate);

        $this->assertSame(SignatureVerificationService::VALID, $this->verify($document)['status']);
    }

    // ── ลำดับการลงนาม ───────────────────────────────────────────────────────

    public function test_a_signature_out_of_its_declared_order_is_flagged(): void
    {
        [$document] = $this->signedDocument();

        // อ้างว่าเป็นลำดับที่สอง ทั้งที่เป็นลายเซ็นแรกและลายเซ็นเดียวของหนังสือ
        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update(['signing_sequence' => 2]);

        $this->assertSame(SignatureVerificationService::SIGNATURE_ORDER_INVALID, $this->verify($document)['status']);
    }

    // ── ใบรับรองและ trusted timestamp ────────────────────────────────────────

    /** ประกาศว่าเป็นลายเซ็นดิจิทัลแต่ไม่มีใบรับรอง = อ้างสิ่งที่พิสูจน์ไม่ได้ */
    public function test_claiming_a_digital_signature_without_a_certificate_fails(): void
    {
        [$document] = $this->signedDocument();

        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update(['signature_type' => SignatureEvidence::TYPE_DIGITAL]);

        $this->assertSame(SignatureVerificationService::INVALID, $this->verify($document)['status']);
    }

    public function test_a_certificate_that_expired_before_signing_is_reported(): void
    {
        [$document] = $this->signedDocument();

        $this->attachCertificate($document, [
            'certificate_valid_from' => now()->subYears(3),
            'certificate_valid_to' => now()->subYear(),
        ]);

        $this->assertSame(SignatureVerificationService::CERTIFICATE_EXPIRED, $this->verify($document)['status']);
    }

    public function test_a_certificate_revoked_before_signing_is_reported(): void
    {
        [$document] = $this->signedDocument();

        $this->attachCertificate($document, ['certificate_revoked_at' => now()->subDay()]);

        $this->assertSame(SignatureVerificationService::CERTIFICATE_REVOKED, $this->verify($document)['status']);
    }

    /** ผู้ออกใบรับรองนอกรายชื่อที่หน่วยงานประกาศว่าเชื่อถือ */
    public function test_a_certificate_from_an_untrusted_issuer_is_reported(): void
    {
        [$document] = $this->signedDocument();

        $this->attachCertificate($document, ['certificate_issuer' => 'CN=ผู้ออกที่ไม่รู้จัก']);

        $officer = $this->userWith(['saraban.policy.manage', 'saraban.document.view']);
        $policies = app(PolicyCenter::class);
        $policies->draft(Policy::KEY_SIGNATURE, [
            'trusted_certificate_issuers' => ['CN=Thai Government CA'],
            'trusted_timestamp_authorities' => [],
        ], $officer, 'ประกาศรายชื่อผู้ออกใบรับรองที่เชื่อถือ');
        $policies->publish(Policy::KEY_SIGNATURE, $officer);

        $this->assertSame(SignatureVerificationService::CERTIFICATE_UNTRUSTED, $this->verify($document)['status']);
    }

    /** เวลาที่ประทับมาก่อนเวลาที่ลงนาม = เวลาที่อ้างเชื่อไม่ได้ */
    public function test_a_timestamp_that_predates_the_signature_is_invalid(): void
    {
        [$document] = $this->signedDocument();

        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update([
                'timestamp_authority' => 'TSA ของหน่วยงาน',
                'timestamp_token_hash' => hash('sha256', 'token'),
                'timestamped_at' => now()->subDays(2),
            ]);

        $this->assertSame(SignatureVerificationService::TIMESTAMP_INVALID, $this->verify($document)['status']);
    }

    /** @param array<string,mixed> $attributes */
    private function attachCertificate(Document $document, array $attributes = []): void
    {
        DB::table('saraban_signature_evidences')
            ->where('document_id', $document->id)
            ->update([
                'signature_type' => SignatureEvidence::TYPE_DIGITAL,
                'certificate_serial' => '0A1B2C3D',
                'certificate_subject' => 'CN=ผู้ลงนามทดสอบ',
                'certificate_issuer' => 'CN=Thai Government CA',
                'certificate_fingerprint' => hash('sha256', 'fingerprint'),
                'certificate_valid_from' => now()->subYear(),
                'certificate_valid_to' => now()->addYear(),
                ...$attributes,
            ]);
    }

    // ── วงจรการเพิกถอน ──────────────────────────────────────────────────────

    public function test_a_revocation_needs_a_reason_and_becomes_pending(): void
    {
        [$document] = $this->finalizedDocument();
        $requester = $this->userWith(['saraban.document.view', 'saraban.signature.revoke']);

        $this->actAs($requester);

        // เหตุผลสั้นเกินกว่าจะอ่านแล้วเข้าใจ ต้องไม่ผ่าน
        $this->postJson("/api/saraban/documents/{$document->id}/revocations", ['reason' => 'ผิด'])
            ->assertStatus(422);

        $this->postJson("/api/saraban/documents/{$document->id}/revocations", [
            'reason' => 'ลงนามผิดฉบับ ต้องออกหนังสือใหม่แทนฉบับนี้',
        ])->assertCreated();

        $this->assertSame(SignatureVerificationService::REVOCATION_PENDING, $this->verify($document)['status']);
    }

    public function test_an_approved_revocation_cancels_the_document_without_deleting_anything(): void
    {
        [$document, $revocation] = $this->pendingRevocation();

        $approver = $this->userWith(['saraban.document.view', 'saraban.signature.revoke_approve']);
        $this->actAs($approver);

        $this->postJson("/api/saraban/revocations/{$revocation->id}/decision", [
            'decision' => 'approve',
            'note' => 'ตรวจสอบแล้วสมควรเพิกถอน',
        ])->assertOk();

        $this->assertSame(Document::STATUS_CANCELLED, $document->fresh()->status);
        $this->assertSame(SignatureVerificationService::REVOKED, $this->verify($document)['status']);

        // **ไม่ลบอะไรเลย** — หลักฐานและไฟล์ยังอยู่ครบเพื่อให้ตรวจย้อนหลังได้
        $evidence = SignatureEvidence::query()->where('document_id', $document->id)->firstOrFail();
        Storage::disk(StoredFile::DISK)->assertExists($evidence->version->pdf_file_path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'saraban.signature.revocation_approved']);
    }

    public function test_a_rejected_revocation_leaves_the_document_usable(): void
    {
        [$document, $revocation] = $this->pendingRevocation();

        $approver = $this->userWith(['saraban.document.view', 'saraban.signature.revoke_approve']);
        $this->actAs($approver);

        $this->postJson("/api/saraban/revocations/{$revocation->id}/decision", [
            'decision' => 'reject',
            'note' => 'ตรวจสอบแล้วหนังสือฉบับนี้ถูกต้อง ไม่ต้องเพิกถอน',
        ])->assertOk();

        $this->assertSame(SignatureRevocation::STATUS_REJECTED, $revocation->fresh()->status);
        $this->assertNotSame(Document::STATUS_CANCELLED, $document->fresh()->status);

        // คำขอที่ถูกปฏิเสธยังอยู่ในทะเบียน — ต้องตอบได้ว่าเคยมีคนขอเพิกถอน
        $this->assertSame(1, SignatureRevocation::query()->where('document_id', $document->id)->count());
    }

    /** ผู้ขอกับผู้ตัดสินต้องเป็นคนละคน (SoT §26) */
    public function test_the_requester_cannot_approve_their_own_revocation(): void
    {
        [$document, $revocation, $requester] = $this->pendingRevocation();

        $requester->givePermissionTo(Permission::findOrCreate('saraban.signature.revoke_approve', 'web'));
        $this->actAs($requester->fresh());

        $this->postJson("/api/saraban/revocations/{$revocation->id}/decision", ['decision' => 'approve'])
            ->assertStatus(422);

        $this->assertNotSame(Document::STATUS_CANCELLED, $document->fresh()->status);
    }

    /** การเพิกถอนเป็นการกระทำที่ต้องยืนยันตัวตนซ้ำ (SoT §24) */
    public function test_revocation_requires_recent_re_authentication(): void
    {
        [$document] = $this->finalizedDocument();
        $requester = $this->userWith(['saraban.document.view', 'saraban.signature.revoke']);

        $this->actAs($requester, null);

        /*
         * 423 Locked คือรหัสที่ด่าน `step_up` ของระบบใช้อยู่แล้ว — ต่างจาก 403
         * โดยตั้งใจ เพราะ "ยืนยันตัวตนใหม่แล้วทำได้" ไม่เหมือน "ไม่มีสิทธิ์เลย"
         * หน้าจอจึงแยกได้ว่าควรขอรหัสผ่านซ้ำ หรือควรบอกว่าทำไม่ได้
         */
        $this->postJson("/api/saraban/documents/{$document->id}/revocations", [
            'reason' => 'ลงนามผิดฉบับ ต้องออกหนังสือใหม่แทนฉบับนี้',
        ])->assertStatus(423);
    }

    public function test_revoking_without_the_permission_is_refused(): void
    {
        [$document] = $this->finalizedDocument();

        $this->actAs($this->userWith(['saraban.document.view']));

        $this->postJson("/api/saraban/documents/{$document->id}/revocations", [
            'reason' => 'ลงนามผิดฉบับ ต้องออกหนังสือใหม่แทนฉบับนี้',
        ])->assertForbidden();
    }

    public function test_deciding_without_the_approval_permission_is_refused(): void
    {
        [, $revocation] = $this->pendingRevocation();

        $this->actAs($this->userWith(['saraban.document.view', 'saraban.signature.revoke']));

        $this->postJson("/api/saraban/revocations/{$revocation->id}/decision", ['decision' => 'approve'])
            ->assertForbidden();
    }

    // ── สิทธิ์ในการดูผลตรวจ ─────────────────────────────────────────────────

    /** ผู้ที่เปิดหนังสือไม่ได้ ต้องไม่รู้แม้กระทั่งว่าลายเซ็นมีปัญหา */
    public function test_verification_detail_is_refused_to_someone_outside_the_scope(): void
    {
        [$document] = $this->signedDocument();

        $this->actAs($this->userWith(['saraban.document.view'], $this->otherUnit->id));

        $this->getJson("/api/saraban/documents/{$document->id}/verification")->assertForbidden();
        $this->getJson("/api/saraban/documents/{$document->id}/verification/report")->assertForbidden();
    }

    public function test_the_verification_api_returns_the_server_side_decision(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);

        $body = $this->getJson("/api/saraban/documents/{$document->id}/verification")->assertOk()->json();

        $this->assertSame(SignatureVerificationService::VALID, $body['status']);
        $this->assertNotEmpty($body['signatures'][0]['checks']);
        $this->assertArrayHasKey('verified_at', $body);
    }

    /** ชุดหลักฐานต้องครบพอที่จะใช้อ้างอิงได้ */
    public function test_the_evidence_package_contains_the_full_record(): void
    {
        [$document, $signer] = $this->signedDocument();
        $this->actAs($signer);

        $body = $this->getJson("/api/saraban/documents/{$document->id}/verification/report")->assertOk()->json();

        $package = $body['evidence_package'][0];

        $this->assertSame($signer->name, $package['signer_name_snapshot']);
        $this->assertNotNull($package['document_hash']);
        $this->assertNotNull($package['reauthenticated_at']);
        $this->assertSame(SignatureEvidence::TYPE_ELECTRONIC, $package['signature_type']);
        // ไม่มีใบรับรอง = `null` ไม่ใช่วัตถุที่มีแต่ค่าว่าง
        $this->assertNull($package['certificate']);
        $this->assertNull($package['trusted_timestamp']);
    }

    // ── หน้าตรวจสอบสาธารณะ ──────────────────────────────────────────────────

    /**
     * **หน้าสาธารณะต้องจับไฟล์ที่ถูกแก้ได้** (ปิด RISK-64)
     *
     * เดิมคิด `is_valid` จาก finalized/cancelled/เมทาดาทาเท่านั้น — ไฟล์ที่ถูก
     * สับเปลี่ยนบนดิสก์จึงยังได้คำตอบว่าใช้อ้างอิงได้
     */
    public function test_public_verification_reports_a_tampered_file_as_unusable(): void
    {
        [$document] = $this->finalizedDocument();

        $evidence = SignatureEvidence::query()->where('document_id', $document->id)->firstOrFail();
        Storage::disk(StoredFile::DISK)->put($evidence->version->pdf_file_path, 'เนื้อหาที่ถูกสับเปลี่ยน');

        $body = $this->getJson('/api/saraban/verify/'.$document->fresh()->verification_hash)
            ->assertOk()
            ->json();

        $this->assertFalse($body['is_valid']);
        $this->assertSame(SignatureVerificationService::DOCUMENT_MODIFIED_AFTER_SIGNING, $body['verification_status']);
    }

    /**
     * **ห้ามรั่วรายละเอียดภายในให้คนทั่วไป**
     *
     * ผู้ถือกระดาษต้องรู้แค่ว่าใช้อ้างอิงได้ไหม · รายละเอียดว่าอำนาจของใคร
     * หมดอายุ ไฟล์อยู่ที่ path ใด หรือ IP ใดเป็นผู้ลงนาม เป็นข้อมูลภายใน
     */
    public function test_public_verification_never_exposes_internal_detail(): void
    {
        [$document] = $this->finalizedDocument();

        $body = $this->getJson('/api/saraban/verify/'.$document->fresh()->verification_hash)
            ->assertOk()
            ->json();

        foreach (['checks', 'document_checks', 'signature_count', 'evidence_package', 'revocation'] as $leak) {
            $this->assertArrayNotHasKey($leak, $body, "หน้าตรวจสอบสาธารณะต้องไม่คืน `{$leak}`");
        }

        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);

        foreach (['ip_address', 'user_agent', 'pdf_file_path', 'auth_token_id', 'document_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $raw);
        }
    }

    /** หนังสือที่ถูกเพิกถอนแล้ว หน้าสาธารณะต้องบอกว่าใช้อ้างอิงไม่ได้ */
    public function test_public_verification_reports_a_revoked_document(): void
    {
        [$document, $revocation] = $this->pendingRevocation();

        $approver = $this->userWith(['saraban.document.view', 'saraban.signature.revoke_approve']);
        $this->actAs($approver);
        $this->postJson("/api/saraban/revocations/{$revocation->id}/decision", [
            'decision' => 'approve', 'note' => 'สมควรเพิกถอน',
        ])->assertOk();

        $body = $this->getJson('/api/saraban/verify/'.$document->fresh()->verification_hash)
            ->assertOk()
            ->json();

        $this->assertFalse($body['is_valid']);
        $this->assertSame(SignatureVerificationService::REVOKED, $body['verification_status']);
    }

    // ── ตัวช่วยของวงจรเพิกถอน ───────────────────────────────────────────────

    /** หนังสือที่ลงนามและออกฉบับสมบูรณ์แล้ว */
    private function finalizedDocument(): array
    {
        [$document, $signer] = $this->signedDocument();

        /*
         * ปกติสายงานเดินหนังสือเป็นผู้ตั้งสถานะนี้เมื่อการลงนามครบทุกขั้น ·
         * เทสต์ชุดนี้ตรวจเรื่องการตรวจสอบลายเซ็น ไม่ใช่การเดินเรื่อง จึงตั้งสถานะ
         * ให้ตรงกับจุดที่การออกฉบับสมบูรณ์เริ่มทำได้ แทนที่จะเดินสายงานทั้งเส้น
         */
        $document->forceFill(['status' => Document::STATUS_SIGNED])->save();

        $this->actAs($signer);
        $this->postJson("/api/saraban/documents/{$document->id}/finalize", [])->assertCreated();

        return [$document->fresh(), $signer];
    }

    /** @return array{0:Document,1:SignatureRevocation,2:User} */
    private function pendingRevocation(): array
    {
        [$document] = $this->finalizedDocument();
        $requester = $this->userWith(['saraban.document.view', 'saraban.signature.revoke']);

        $this->actAs($requester);
        $this->postJson("/api/saraban/documents/{$document->id}/revocations", [
            'reason' => 'ลงนามผิดฉบับ ต้องออกหนังสือใหม่แทนฉบับนี้',
        ])->assertCreated();

        return [
            $document->fresh(),
            SignatureRevocation::query()->where('document_id', $document->id)->firstOrFail(),
            $requester,
        ];
    }
}
