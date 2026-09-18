<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use Database\Seeders\OperationalPermissionSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\VocationalOrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ทดสอบรายงานสารบรรณ สถิติการไหลเวียนเอกสาร ๔ ฝ่าย และศูนย์ตรวจสอบหลักฐานความสอดคล้องตามกฎหมาย (Phase 7)
 */
class VocationalSarabanReportsAndComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(OperationalPermissionSeeder::class);
        $this->seed(VocationalOrgStructureSeeder::class);

        $this->admin = User::factory()->create();
        $permissions = [
            'saraban.report.view',
            'saraban.report.export',
            'saraban.document.view',
            'saraban.document.register',
            'saraban.compliance.view',
        ];

        foreach ($permissions as $permission) {
            $this->admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        DB::table('permission_scopes')->insert([
            'model_type' => User::class,
            'model_id' => $this->admin->id,
            'permission_name' => 'saraban.report.view',
            'scope' => 'ORGANIZATION',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->docType = DocumentType::firstOrCreate(
            ['code' => 'TEST_MEMO'],
            ['name' => 'บันทึกข้อความทดสอบ', 'category' => DocumentType::CATEGORY_INTERNAL],
        );
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill(['last_authenticated_at' => now()])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    public function test_register_report_summary_includes_monthly_by_status_and_by_division(): void
    {
        $this->actAs($this->admin);

        $resDivision = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->first();
        $acadDivision = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->first();

        // หน่วยงานลูกของฝ่ายบริหารทรัพยากร และฝ่ายวิชาการ
        $personnelUnit = OrgUnit::where('code', 'ORG-SEC-HR')->first() ?? $resDivision;
        $curriculumUnit = OrgUnit::where('code', 'ORG-SEC-CURRICULUM')->first() ?? $acadDivision;

        Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->docType->id,
            'owner_org_unit_id' => $personnelUnit->id,
            'direction' => Document::DIRECTION_INTERNAL,
            'status' => Document::STATUS_REGISTERED,
            'subject' => 'บันทึกภายในฝ่ายบริหารทรัพยากร',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'created_by' => $this->admin->id,
        ]);

        Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->docType->id,
            'owner_org_unit_id' => $curriculumUnit->id,
            'direction' => Document::DIRECTION_INCOMING,
            'status' => Document::STATUS_DRAFT,
            'subject' => 'หนังสือรับฝ่ายวิชาการ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/saraban/reports/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'total',
                'monthly',
                'by_status',
                'by_division',
                'since',
            ]);

        $data = $response->json();
        $this->assertGreaterThanOrEqual(2, $data['total']);
        $this->assertNotEmpty($data['by_division']);

        // ตรวจสอบว่ามีข้อมูลฝ่ายบริหารทรัพยากร และฝ่ายวิชาการ
        $divisionCodes = collect($data['by_division'])->pluck('code')->all();
        $this->assertContains('ORG-DIV-RESOURCE', $divisionCodes);
        $this->assertContains('ORG-DIV-ACADEMIC', $divisionCodes);
    }

    public function test_register_report_filters_by_division_and_direction(): void
    {
        $this->actAs($this->admin);

        $resDivision = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->first();
        $acadDivision = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->first();

        $personnelUnit = OrgUnit::where('code', 'ORG-SEC-HR')->first() ?? $resDivision;
        $curriculumUnit = OrgUnit::where('code', 'ORG-SEC-CURRICULUM')->first() ?? $acadDivision;

        $docRes = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->docType->id,
            'owner_org_unit_id' => $personnelUnit->id,
            'direction' => Document::DIRECTION_INCOMING,
            'status' => Document::STATUS_REGISTERED,
            'subject' => 'หนังสือฝ่ายบริหารทรัพยากร',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'created_by' => $this->admin->id,
        ]);

        $docAcad = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->docType->id,
            'owner_org_unit_id' => $curriculumUnit->id,
            'direction' => Document::DIRECTION_INCOMING,
            'status' => Document::STATUS_REGISTERED,
            'subject' => 'หนังสือฝ่ายวิชาการ',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'created_by' => $this->admin->id,
        ]);

        // กรองเฉพาะฝ่ายบริหารทรัพยากร
        $response = $this->getJson('/api/saraban/reports/register?division_id='.$resDivision->id.'&direction=incoming');

        $response->assertOk();
        $rows = collect($response->json('rows'));

        $this->assertTrue($rows->contains('id', $docRes->id));
        $this->assertFalse($rows->contains('id', $docAcad->id));
    }

    public function test_register_report_export_csv_and_compliance_evidence_center(): void
    {
        $this->actAs($this->admin);

        $resDivision = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->first();

        Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->docType->id,
            'owner_org_unit_id' => $resDivision->id,
            'direction' => Document::DIRECTION_INCOMING,
            'status' => Document::STATUS_REGISTERED,
            'subject' => 'หนังสือสำหรับส่งออกรายงาน',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'created_by' => $this->admin->id,
        ]);

        // ทดสอบส่งออกรายงานพร้อมตัวกรอง division
        $exportResponse = $this->get('/api/saraban/reports/export?type=register&format=csv&direction=incoming&division_id='.$resDivision->id);

        $exportResponse->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // ทดสอบศูนย์ตรวจสอบหลักฐานความสอดคล้องตามระเบียบ
        $complianceResponse = $this->getJson('/api/saraban/operations/compliance');

        $complianceResponse->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'summary',
                'topics',
            ]);
    }
}
