<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\RetentionSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 6: Vocational Saraban Document Lifecycle, Retention, Archiving & Disposal Test
 */
class VocationalSarabanRetentionAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private OrgUnit $adminUnit;

    private DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');
        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);

        $this->adminUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ADMIN-DEPT',
            'name' => 'ฝ่ายบริหารทรัพยากร',
            'type' => 'division',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->docType = DocumentType::query()->firstOrCreate(
            ['code' => 'memorandum'],
            ['name' => 'บันทึกข้อความ', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create([
            'org_unit_id' => $this->adminUnit->id,
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

    private function samplePdf(): string
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

    private function createDoc(User $author, string $subject = 'รายงานงบประมาณสถานศึกษา'): Document
    {
        $this->actAs($author);

        $response = $this->post('/api/saraban/documents', [
            'document_type_id' => $this->docType->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'subject' => $subject,
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->adminUnit->id,
            'file' => UploadedFile::fake()->createWithContent('test.pdf', $this->samplePdf()),
        ])->assertCreated();

        return Document::findOrFail($response->json('id'));
    }

    public function test_document_retention_scheduling_and_retention_status_filters(): void
    {
        $officer = $this->userWith([
            'saraban.document.view',
            'saraban.document.create',
            'saraban.config.manage',
        ]);
        $this->actAs($officer);

        $doc1 = $this->createDoc($officer, 'หนังสือจัดซื้อครุภัณฑ์ประจำปี');
        $doc2 = $this->createDoc($officer, 'บันทึกแจ้งเวียนแนวปฏิบัติ');

        // Schedule 5 years retention for doc1
        $res = $this->postJson("/api/saraban/documents/{$doc1->id}/retention", [
            'retain_years' => 5,
        ])->assertCreated();

        $this->assertEquals(5, $res->json('schedule.retain_years'));
        $this->assertEquals('proposed', $res->json('schedule.status'));

        // Check index with retention_status filter
        $listScheduled = $this->getJson('/api/saraban/documents?retention_status=scheduled')->assertOk();
        $this->assertCount(1, $listScheduled->json('data'));
        $this->assertEquals($doc1->id, $listScheduled->json('data.0.id'));

        // Place hold on doc2
        $this->postJson("/api/saraban/documents/{$doc2->id}/holds", [
            'reference' => 'สตง. ๐๑/๒๕๖๙',
            'reason' => 'อยู่ระหว่างการตรวจสอบบัญชีและพัสดุ',
        ])->assertCreated();

        $listHeld = $this->getJson('/api/saraban/documents?retention_status=on_hold')->assertOk();
        $this->assertCount(1, $listHeld->json('data'));
        $this->assertEquals($doc2->id, $listHeld->json('data.0.id'));
        $this->assertNotEmpty($listHeld->json('data.0.legal_holds'));
    }

    public function test_legal_hold_blocks_disposal_until_released(): void
    {
        $policyManager = $this->userWith([
            'saraban.document.view',
            'saraban.document.create',
            'saraban.policy.manage',
            'saraban.config.manage',
        ]);
        $this->actAs($policyManager);

        $doc = $this->createDoc($policyManager, 'สัญญาจ้างก่อสร้างอาคารปฏิบัติการ');

        // Schedule 1 year retention in the past (already due)
        $schedule = RetentionSchedule::create([
            'document_id' => $doc->id,
            'retain_years' => 1,
            'retain_until' => now()->subDay()->toDateString(),
            'status' => RetentionSchedule::STATUS_APPROVED,
        ]);

        // Place legal hold
        $holdRes = $this->postJson("/api/saraban/documents/{$doc->id}/holds", [
            'reference' => 'คดีหมายเลขดำที่ ๑๑๒/๒๕๖๙',
            'reason' => 'รอคำพิพากษาศาลปกครองสูงสุด',
        ])->assertCreated();

        $holdId = $holdRes->json('hold.id');

        // Attempt disposal should fail because legal hold is active
        $this->postJson("/api/saraban/retention/{$schedule->id}/dispose")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'หนังสือฉบับนี้ถูกอายัดอยู่ ทำลายไม่ได้จนกว่าจะปลดการอายัด']);

        // Release legal hold
        $this->postJson("/api/saraban/holds/{$holdId}/release", [
            'note' => 'คดีสิ้นสุดแล้วตามคำพิพากษาถึงที่สุด',
        ])->assertOk();

        // Now disposal succeeds with separation of duties
        $disposeRes = $this->postJson("/api/saraban/retention/{$schedule->id}/dispose", [
            'note' => 'ทำลายไฟล์เอกสารตามกำหนดการและคำสั่งคณะกรรมการทำลายหนังสือ',
        ])->assertOk();

        $this->assertNotNull($disposeRes->json('disposed_file_hash'));
        $schedule->refresh();
        $this->assertEquals(RetentionSchedule::STATUS_DISPOSED, $schedule->status);
        $this->assertNotNull($schedule->disposed_at);
        $this->assertNotNull($schedule->disposed_file_hash);

        // Verify document row still exists in register (Non-Destructive)
        $this->assertDatabaseHas('saraban_documents', ['id' => $doc->id]);
    }
}
