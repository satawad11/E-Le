<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Overtime\OvertimeOrder;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\User;
use Database\Seeders\OperationalPermissionSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\VocationalOrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VocationalSarabanIntegrationAndExchangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrgUnit $orgUnit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_uploads');

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(OperationalPermissionSeeder::class);
        $this->seed(VocationalOrgStructureSeeder::class);

        $this->orgUnit = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->first() ?? OrgUnit::first();

        $this->admin = User::factory()->create([
            'org_unit_id' => $this->orgUnit->id,
        ]);

        $permissions = [
            'saraban.document.view',
            'saraban.document.create',
            'saraban.distribution.track',
            'saraban.incoming.register',
        ];

        foreach ($permissions as $perm) {
            $this->admin->givePermissionTo(Permission::findOrCreate($perm, 'web'));
        }
    }

    public function test_can_generate_cross_module_draft_payload_from_leave_request(): void
    {
        $leaveType = LeaveType::create([
            'name' => 'ลาพักผ่อน',
            'description' => 'ลาพักผ่อนประจำปี',
            'max_days' => 10,
            'requires_doc' => false,
        ]);

        $leave = Leave::create([
            'user_id' => $this->admin->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 3,
            'reason' => 'พักผ่อนประจำปี',
            'status' => 'Approved',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/saraban/templates/cross-module-draft?source_type=leave&source_id={$leave->id}");

        $response->assertStatus(200)
            ->assertJsonPath('draft.template_id', 'college_order')
            ->assertJsonPath('draft.metadata.source_module', 'leave');

        $this->assertStringContainsString('ลาพักผ่อน', $response->json('draft.subject'));
    }

    public function test_can_generate_cross_module_draft_payload_from_overtime_order(): void
    {
        $order = OvertimeOrder::create([
            'order_number' => 'OT-20260916-01',
            'title' => 'จัดทำแผนปฏิบัติการประจำปี',
            'org_unit_id' => $this->orgUnit->id,
            'timezone' => 'Asia/Bangkok',
            'scheduled_start' => now(),
            'scheduled_end' => now()->addHours(4),
            'status' => 'approved',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/saraban/templates/cross-module-draft?source_type=overtime&source_id={$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('draft.template_id', 'college_order')
            ->assertJsonPath('draft.metadata.source_module', 'overtime');

        $this->assertStringContainsString('จัดทำแผนปฏิบัติการประจำปี', $response->json('draft.subject'));
    }

    public function test_can_build_and_download_interagency_evidence_package(): void
    {
        $docType = DocumentType::firstOrCreate(
            ['code' => 'OUTGOING_GENERAL'],
            ['name' => 'หนังสือส่งทั่วไป', 'category' => DocumentType::CATEGORY_OUTGOING, 'is_active' => true]
        );

        $doc = Document::create([
            'document_type_id' => $docType->id,
            'direction' => Document::DIRECTION_OUTGOING,
            'subject' => 'หนังสือแจ้งความร่วมมือทางวิชาการระหว่างสถานศึกษา',
            'summary' => 'เอกสารส่งข้ามวิทยาลัย',
            'urgency' => Document::URGENCY_NORMAL,
            'confidentiality' => Document::CONFIDENTIALITY_NORMAL,
            'status' => Document::STATUS_DRAFT,
            'owner_org_unit_id' => $this->orgUnit->id,
            'created_by' => $this->admin->id,
        ]);

        // จำลองไฟล์ PDF สำหรับเวอร์ชัน
        $pdfPath = 'saraban/documents/test_doc.pdf';
        Storage::disk('private_uploads')->put($pdfPath, '%PDF-1.4 Mock Content');

        $version = DocumentVersion::create([
            'document_id' => $doc->id,
            'version_no' => 1,
            'stage' => DocumentVersion::STAGE_ORIGINAL,
            'pdf_file_path' => $pdfPath,
            'file_hash' => hash('sha512', '%PDF-1.4 Mock Content'),
            'created_by' => $this->admin->id,
        ]);

        $doc->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->admin)
            ->get("/api/saraban/documents/{$doc->id}/dispatch-package?target_agency_name=วิทยาลัยเทคนิคเชียงใหม่");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/zip');
    }

    public function test_can_import_interagency_document_package(): void
    {
        $manifest = [
            'version' => '1.0',
            'standard' => 'TH-GOV-SARABAN-E-DISPATCH',
            'sender' => [
                'institution_code' => 'VOC-EXT-999',
                'institution_name' => 'วิทยาลัยเทคนิคพิจิตร',
            ],
            'document' => [
                'document_number' => 'ศธ ๐๖๐๑/๙๙๙',
                'subject' => 'ขอเชิญเข้าร่วมประชุมสัมมนาเชิงปฏิบัติการวิชาชีพ',
                'summary' => 'การประชุมสัมมนาครูผู้สอนแผนกวิชาช่างยนต์',
                'urgency' => 'normal',
                'confidentiality' => 'normal',
                'content_hash' => hash('sha512', 'sample_manifest_content'),
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->postJson('/api/saraban/incoming/import-package', [
                'manifest' => $manifest,
                'owner_org_unit_id' => $this->orgUnit->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('document.subject', 'ขอเชิญเข้าร่วมประชุมสัมมนาเชิงปฏิบัติการวิชาชีพ')
            ->assertJsonPath('document.direction', 'incoming');

        $this->assertDatabaseHas('saraban_documents', [
            'subject' => 'ขอเชิญเข้าร่วมประชุมสัมมนาเชิงปฏิบัติการวิชาชีพ',
            'direction' => 'incoming',
        ]);
    }
}
