<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentAnnotation;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\SignatureEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VocationalSarabanSigningAndWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    private OrgUnit $rootOrg;

    private OrgUnit $academicDivision;

    private DocumentType $internalDocType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->rootOrg = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ROOT-VOC',
            'name' => 'วิทยาลัยเทคนิคดอนเมือง',
            'type' => 'organization',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->academicDivision = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ACAD',
            'name' => 'ฝ่ายวิชาการ',
            'type' => 'division',
            'parent_id' => $this->rootOrg->id,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->internalDocType = DocumentType::query()->firstOrCreate(
            ['code' => 'internal'],
            ['name' => 'บันทึกข้อความ', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    private function makeUser(OrgUnit $unit, array $permissions = []): User
    {
        $user = User::factory()->create([
            'org_unit_id' => $unit->id,
            'password' => Hash::make(self::PASSWORD),
        ]);

        foreach ($permissions as $perm) {
            $permission = Permission::findOrCreate($perm, 'web');
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

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

    private function actAs(User $user, ?string $authenticatedAt = 'now'): void
    {
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

        $pdf .= 'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function createDocument(User $creator, string $title = 'ขออนุมัติโครงการพัฒนาทักษะวิชาชีพ'): Document
    {
        $this->actAs($creator);
        $created = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->internalDocType->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => $title,
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->academicDivision->id,
            'file' => UploadedFile::fake()->createWithContent('document.pdf', $this->realPdf()),
        ])
            ->assertCreated()
            ->json();

        return Document::findOrFail($created['id']);
    }

    public function test_digital_signing_requires_step_up_creates_evidence_and_records_audit(): void
    {
        $director = $this->makeUser($this->academicDivision, [
            'saraban.document.view',
            'saraban.document.create',
            'saraban.signature.sign',
        ]);

        $document = $this->createDocument($director, 'ขออนุมัติโครงการพัฒนาทักษะวิชาชีพ');

        // Act with expired authentication (authenticated 20 minutes ago -> window is 10 min)
        $this->actAs($director, now()->subMinutes(20)->toDateTimeString());

        // Try signing without recent step-up authentication -> must fail with 423 Locked
        $response = $this->postJson("/api/saraban/documents/{$document->id}/sign", [
            'page' => 1,
            'x' => 350,
            'y' => 600,
            'strokes' => [
                [['x' => 10, 'y' => 10], ['x' => 50, 'y' => 40], ['x' => 90, 'y' => 10]],
            ],
        ]);

        $response->assertStatus(423);

        // Perform step-up re-authentication (fresh token with now timestamp)
        $this->actAs($director, 'now');

        // Sign document with signature stroke coordinates and accompanying note
        $response = $this->postJson("/api/saraban/documents/{$document->id}/sign", [
            'page' => 1,
            'x' => 350,
            'y' => 600,
            'strokes' => [
                [['x' => 10, 'y' => 10], ['x' => 50, 'y' => 40], ['x' => 90, 'y' => 10]],
            ],
            'objects' => [
                [
                    'page' => 1,
                    'type' => DocumentAnnotation::TYPE_NOTE,
                    'x' => 350,
                    'y' => 660,
                    'payload' => ['text' => '(รองผู้อำนวยการฝ่ายวิชาการ)'],
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('saraban_signature_evidences', [
            'document_id' => $document->id,
            'signer_id' => $director->id,
            'signature_type' => SignatureEvidence::TYPE_ELECTRONIC,
        ]);

        $document->refresh();
        $this->assertSame(DocumentVersion::STAGE_SIGNED, $document->currentVersion->stage);
        $this->assertSame(1, SignatureEvidence::query()->where('document_id', $document->id)->count());
    }

    public function test_endorsement_by_vocational_deputy_director_records_notes_without_signature_evidence(): void
    {
        $deputy = $this->makeUser($this->academicDivision, [
            'saraban.document.view',
            'saraban.document.create',
            'saraban.endorse.create',
        ]);

        $document = $this->createDocument($deputy, 'เสนอพิจารณาแผนการสอนภาคเรียนที่ ๑');

        $this->actAs($deputy);

        // Endorse with note and checkmark
        $response = $this->postJson("/api/saraban/documents/{$document->id}/endorse", [
            'objects' => [
                [
                    'page' => 1,
                    'type' => DocumentAnnotation::TYPE_NOTE,
                    'x' => 100,
                    'y' => 500,
                    'payload' => ['text' => 'เห็นควรอนุมัติตามเสนอ'],
                ],
                [
                    'page' => 1,
                    'type' => DocumentAnnotation::TYPE_CHECKMARK,
                    'x' => 80,
                    'y' => 500,
                ],
            ],
        ]);

        $response->assertCreated();

        // Must NOT create signature evidence per SoT 15 (endorsement != signature)
        $this->assertDatabaseMissing('saraban_signature_evidences', [
            'document_id' => $document->id,
        ]);

        $document->refresh();
        $this->assertSame(DocumentVersion::STAGE_ENDORSED, $document->currentVersion->stage);
    }

    public function test_workspace_stamping_and_finalization_with_verification(): void
    {
        $officer = $this->makeUser($this->academicDivision, [
            'saraban.document.view',
            'saraban.document.create',
            'saraban.incoming.stamp',
            'saraban.signature.sign',
        ]);

        $document = $this->createDocument($officer, 'หนังสือแจ้งผลการประเมิน');

        $this->actAs($officer);

        // Save annotation on PDF workspace (PUT route)
        $response = $this->putJson("/api/saraban/documents/{$document->id}/annotations", [
            'annotations' => [
                [
                    'page' => 1,
                    'type' => DocumentAnnotation::TYPE_TEXT,
                    'x' => 100,
                    'y' => 100,
                    'payload' => ['text' => 'เอกสารตรวจแล้ว'],
                ],
            ],
        ]);
        $response->assertOk();

        // Render stamped version (creates a new DocumentVersion, returns 201)
        $response = $this->postJson("/api/saraban/documents/{$document->id}/annotations/render");
        $response->assertCreated();

        // Sign document
        $this->postJson("/api/saraban/documents/{$document->id}/sign", [
            'page' => 1,
            'x' => 300,
            'y' => 500,
            'strokes' => [[['x' => 0, 'y' => 0], ['x' => 20, 'y' => 20]]],
        ])->assertCreated();

        $document->update(['status' => Document::STATUS_SIGNED]);

        // Finalize document with QR verification code
        $document->refresh();
        $response = $this->postJson("/api/saraban/documents/{$document->id}/finalize", [
            'placement' => [
                'page' => 1,
                'x' => 450,
                'y' => 50,
                'size' => 60,
            ],
        ]);
        $response->assertCreated();

        $document->refresh();
        $this->assertNotNull($document->finalized_at);
        $this->assertSame(DocumentVersion::STAGE_FINAL, $document->currentVersion->stage);

        // Test public verification endpoint using verification hash
        $verificationHash = $document->verification_hash;
        $this->assertNotEmpty($verificationHash);

        $verifyResponse = $this->getJson("/api/saraban/verify/{$verificationHash}");
        $verifyResponse->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('subject', 'หนังสือแจ้งผลการประเมิน');
    }
}
