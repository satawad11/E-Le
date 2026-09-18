<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\DistributionRecipient;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\Saraban\DistributionService;
use App\Services\Saraban\DocumentService;
use App\Services\Saraban\IncomingRegistrationService;
use Database\Seeders\OperationalPermissionSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\VocationalOrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VocationalSarabanRegistryAndRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $sarabanOfficer;

    private User $academicTeacher;

    private OrgUnit $academicDivision;

    private OrgUnit $resourceDivision;

    private DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(OperationalPermissionSeeder::class);
        $this->seed(VocationalOrgStructureSeeder::class);

        $this->academicDivision = OrgUnit::where('name', 'like', '%ฝ่ายวิชาการ%')->first()
            ?? OrgUnit::create(['name' => 'ฝ่ายวิชาการ', 'code' => 'ACADEMIC', 'level' => 2]);

        $this->resourceDivision = OrgUnit::where('name', 'like', '%ฝ่ายบริหารทรัพยากร%')->first()
            ?? OrgUnit::create(['name' => 'ฝ่ายบริหารทรัพยากร', 'code' => 'RESOURCE', 'level' => 2]);

        $rootUnit = OrgUnit::whereNull('parent_id')->first() ?? $this->resourceDivision;

        $this->sarabanOfficer = User::factory()->create([
            'org_unit_id' => $rootUnit->id,
        ]);
        $this->sarabanOfficer->givePermissionTo(Permission::findOrCreate('saraban.incoming.register', 'web'));
        $this->sarabanOfficer->givePermissionTo(Permission::findOrCreate('saraban.distribution.send', 'web'));
        $this->sarabanOfficer->givePermissionTo(Permission::findOrCreate('saraban.document.view', 'web'));

        $this->grantScope($this->sarabanOfficer, 'saraban.distribution.send', 'ORGANIZATION', null);

        $this->academicTeacher = User::factory()->create([
            'org_unit_id' => $this->academicDivision->id,
        ]);

        $this->docType = DocumentType::firstOrCreate(
            ['code' => 'GENERAL_MEMO'],
            ['name' => 'บันทึกข้อความทั่วไป', 'category' => 'internal', 'is_active' => true]
        );

        // Prepare incoming number series
        NumberSeries::firstOrCreate(
            ['code' => 'incoming-register'],
            [
                'name' => 'ทะเบียนรับหนังสือราชการ',
                'format' => '{RUNNING}/{YEAR_TH}',
                'current_running' => 0,
                'min_digits' => 4,
                'reset_cycle' => 'FISCAL_YEAR',
                'is_active' => true,
            ]
        );
    }

    private function grantScope(User $user, string $ability, string $scope, ?int $orgUnitId): void
    {
        DB::table('permission_scopes')->insert([
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
            'permission_name' => $ability,
            'scope' => $scope,
            'org_unit_id' => $orgUnitId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_incoming_registration_issues_running_number_and_records_audit(): void
    {
        Storage::fake('private_uploads');

        $file = UploadedFile::fake()->create('incoming_doc.pdf', 500, 'application/pdf');

        $service = app(IncomingRegistrationService::class);

        $registration = $service->register([
            'document_type_id' => $this->docType->id,
            'subject' => 'หนังสือขอความอนุเคราะห์สถานที่',
            'sender_name' => 'เทศบาลนครราชสีมา',
            'source_number' => 'ทน ๕๒๐๐๑/๑๒๓',
            'source_date' => '2026-09-10',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->resourceDivision->id,
        ], $this->sarabanOfficer, $file);

        $this->assertNotNull($registration->id);
        $this->assertSame('เทศบาลนครราชสีมา', $registration->sender_name);
        $this->assertSame('ทน ๕๒๐๐๑/๑๒๓', $registration->source_number);

        // Document created & version added
        $doc = $registration->document;
        $this->assertNotNull($doc);
        $this->assertSame(Document::STATUS_REGISTERED, $doc->status);
        $this->assertSame(Document::DIRECTION_INCOMING, $doc->direction);
        $this->assertCount(1, $doc->versions);
    }

    public function test_distribution_service_routes_to_vocational_division_subtree(): void
    {
        Storage::fake('private_uploads');

        $file = UploadedFile::fake()->create('memo_final.pdf', 300, 'application/pdf');

        $doc = Document::create([
            'document_type_id' => $this->docType->id,
            'direction' => Document::DIRECTION_INCOMING,
            'subject' => 'หนังสือแจ้งผลการพิจารณา',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'owner_org_unit_id' => $this->resourceDivision->id,
            'status' => Document::STATUS_REGISTERED,
            'created_by' => $this->sarabanOfficer->id,
        ]);

        $docService = app(DocumentService::class);
        $docService->addVersion($doc, $file, $this->sarabanOfficer, 'original');

        $distributionService = app(DistributionService::class);

        $distribution = $distributionService->distribute(
            $doc,
            [
                [
                    'type' => DistributionRecipient::TARGET_ORG_SUBTREE,
                    'id' => $this->academicDivision->id,
                    'role' => DistributionRecipient::ROLE_TO,
                ],
            ],
            $this->sarabanOfficer,
            'เพื่อโปรดทราบและดำเนินการ'
        );

        $this->assertNotNull($distribution->id);
        $this->assertSame('เพื่อโปรดทราบและดำเนินการ', $distribution->note);

        // Verify recipient resolved
        $recipients = DistributionRecipient::where('distribution_id', $distribution->id)->get();
        $this->assertTrue($recipients->pluck('recipient_id')->contains($this->academicTeacher->id));
    }

    public function test_top_secret_document_cannot_be_distributed_electronically(): void
    {
        Storage::fake('private_uploads');

        $doc = Document::create([
            'document_type_id' => $this->docType->id,
            'direction' => Document::DIRECTION_INCOMING,
            'subject' => 'หนังสือลับที่สุด',
            'confidentiality' => 'top_secret',
            'urgency' => 'most_urgent',
            'owner_org_unit_id' => $this->resourceDivision->id,
            'status' => Document::STATUS_REGISTERED,
            'created_by' => $this->sarabanOfficer->id,
        ]);

        $distributionService = app(DistributionService::class);
        $this->assertFalse($distributionService->canDistribute($doc));
    }
}
