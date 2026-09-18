<?php

namespace Tests\Feature;

use App\Models\Document\DocumentDataSource;
use App\Models\Document\DocumentFieldDefinition;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\DocumentEngine\DataProviderRegistry;
use App\Services\DocumentEngine\FieldDictionarySynchronizer;
use App\Services\DocumentEngine\Providers\OfficialSarabanDataProvider;
use App\Services\DocumentEngine\Providers\PersonnelProfileDataProvider;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ทดสอบการทำงานของ Data Provider และ Field Dictionary สำหรับแกนกลางระบบ (Phase 1)
 *
 * ตรวจสอบ:
 * 1. DataProviderRegistry ค้นพบและลงทะเบียน PersonnelProfileDataProvider และ OfficialSarabanDataProvider
 * 2. PersonnelProfileDataProvider สามารถดึงข้อมูลบุคลากร สังกัด ตำแหน่ง และข้อมูลสถาบันได้อย่างถูกต้อง
 * 3. OfficialSarabanDataProvider สามารถดึงข้อมูลเลขที่หนังสือ ชั้นความเร็ว ชั้นความลับ และฟิลด์สารบรรณได้
 * 4. FieldDictionarySynchronizer สามารถบันทึกฟิลด์และแหล่งข้อมูลลง Database ได้อย่างสมบูรณ์และ idempotent
 */
class CoreDataDictionaryProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    public function test_registry_contains_personnel_and_saraban_providers(): void
    {
        $registry = app(DataProviderRegistry::class);

        $this->assertTrue($registry->has('PERSONNEL_PROFILE_DETAIL'));
        $this->assertTrue($registry->has('SARABAN_DOCUMENT_DETAIL'));
        $this->assertTrue($registry->has('LEAVE_REQUEST_DETAIL'));

        $personnelProvider = $registry->get('PERSONNEL_PROFILE_DETAIL');
        $this->assertInstanceOf(PersonnelProfileDataProvider::class, $personnelProvider);
        $this->assertSame('core', $personnelProvider->module());

        $sarabanProvider = $registry->get('SARABAN_DOCUMENT_DETAIL');
        $this->assertInstanceOf(OfficialSarabanDataProvider::class, $sarabanProvider);
        $this->assertSame('saraban', $sarabanProvider->module());

        $all = $registry->all();
        $this->assertArrayHasKey('PERSONNEL_PROFILE_DETAIL', $all);
        $this->assertArrayHasKey('SARABAN_DOCUMENT_DETAIL', $all);
        $this->assertArrayHasKey('LEAVE_REQUEST_DETAIL', $all);
    }

    public function test_personnel_profile_data_provider_resolves_user_context(): void
    {
        $org = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'ภาควิชาวิทยาการคอมพิวเตอร์',
            'code' => 'CS-DEPT',
            'type' => 'DEPARTMENT',
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'name' => 'สมเกียรติ มั่นคง',
            'email' => 'somkiat@example.com',
            'org_unit_id' => $org->id,
            'is_active' => true,
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'position' => 'อาจารย์',
        ]);

        $user->refresh();

        $provider = new PersonnelProfileDataProvider;
        $data = $provider->fields(['user' => $user]);

        $this->assertSame('สมเกียรติ มั่นคง', $data['personnel.full_name']);
        $this->assertSame('somkiat@example.com', $data['user.email']);
        $this->assertSame('อาจารย์', $data['personnel.position']);
        $this->assertSame('ภาควิชาวิทยาการคอมพิวเตอร์', $data['org_unit.name']);
        $this->assertSame('CS-DEPT', $data['org_unit.code']);

        $catalog = $provider->catalog();
        $this->assertNotEmpty($catalog);
        $catalogKeys = array_column($catalog, 'code');
        $this->assertContains('personnel.full_name', $catalogKeys);
        $this->assertContains('organization.name', $catalogKeys);
    }

    public function test_saraban_data_provider_resolves_context_and_thai_dates(): void
    {
        $provider = new OfficialSarabanDataProvider;

        $context = [
            'doc_no' => 'อว 0601/1234',
            'title' => 'ขออนุมัติจัดโครงการสัมมนาวิชาการ',
            'summary' => 'สรุปรายละเอียดโครงการสัมมนา',
            'speed' => 'URGENT_1',
            'secret' => 'SECRET_1',
            'org_unit_name' => 'คณะวิทยาศาสตร์และเทคโนโลยี',
            'document_date' => '2026-09-16',
        ];

        $data = $provider->fields($context);

        $this->assertSame('อว 0601/1234', $data['saraban.document_number']);
        $this->assertSame('ขออนุมัติจัดโครงการสัมมนาวิชาการ', $data['saraban.subject']);
        $this->assertSame('ด่วนที่สุด', $data['saraban.urgency']);
        $this->assertSame('ลับ', $data['saraban.confidentiality']);
        $this->assertSame('คณะวิทยาศาสตร์และเทคโนโลยี', $data['saraban.owner_org_unit']);
        $this->assertStringContainsString('2569', $data['saraban.date_thai']);

        $catalog = $provider->catalog();
        $this->assertNotEmpty($catalog);
        $catalogKeys = array_column($catalog, 'code');
        $this->assertContains('saraban.document_number', $catalogKeys);
        $this->assertContains('saraban.verify_url', $catalogKeys);
    }

    public function test_synchronizer_registers_new_data_sources_and_fields_in_database(): void
    {
        $registry = app(DataProviderRegistry::class);
        $registry->sync();

        $synchronizer = app(FieldDictionarySynchronizer::class);
        $synchronizer->sync();

        // ตรวจสอบ Data Source ใน database
        $personnelSource = DocumentDataSource::where('code', 'PERSONNEL_PROFILE_DETAIL')->first();
        $this->assertNotNull($personnelSource);
        $this->assertSame('core', $personnelSource->module);

        $sarabanSource = DocumentDataSource::where('code', 'SARABAN_DOCUMENT_DETAIL')->first();
        $this->assertNotNull($sarabanSource);
        $this->assertSame('saraban', $sarabanSource->module);

        // ตรวจสอบ Field Definitions ใน database
        $fieldPersonnelName = DocumentFieldDefinition::where('code', 'personnel.full_name')->first();
        $this->assertNotNull($fieldPersonnelName);
        $this->assertSame('TEXT', $fieldPersonnelName->data_type);
        $this->assertSame('ข้อมูลบุคลากร', $fieldPersonnelName->group_th);

        $fieldSarabanDocNo = DocumentFieldDefinition::where('code', 'saraban.document_number')->first();
        $this->assertNotNull($fieldSarabanDocNo);
        $this->assertSame('TEXT', $fieldSarabanDocNo->data_type);
        $this->assertSame('หนังสือราชการ', $fieldSarabanDocNo->group_th);
    }
}
