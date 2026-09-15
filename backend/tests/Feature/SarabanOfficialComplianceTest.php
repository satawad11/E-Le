<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentNumber;
use App\Models\Saraban\DocumentType;
use App\Models\Saraban\DocumentVersion;
use App\Models\Saraban\IncomingRegistration;
use App\Models\Saraban\NumberSeries;
use App\Models\User;
use App\Services\Saraban\DistributionException;
use App\Services\Saraban\DistributionService;
use App\Services\Saraban\OfficialSarabanFilenameService;
use App\Services\Saraban\RegisterReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ทดสอบการปฏิบัติตามระเบียบสำนักนายกรัฐมนตรี ว่าด้วยงานสารบรรณ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔
 * สำหรับสถานศึกษาอาชีวศึกษา (สังกัดกระทรวงศึกษาธิการ - MOE)
 */
class SarabanOfficialComplianceTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unit;

    private DocumentType $type;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_uploads');

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => '06001',
            'name' => 'วิทยาลัยอาชีวศึกษา',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::create([
            'code' => 'outgoing-order',
            'name' => 'หนังสือส่งภายนอก',
            'abbreviation' => 'สภ.',
            'category' => DocumentType::CATEGORY_OUTGOING,
        ]);

        $this->officer = User::factory()->create([
            'org_unit_id' => $this->unit->id,
        ]);
        $this->officer->givePermissionTo([
            'saraban.document.view',
            'saraban.document.create',
            'saraban.distribution.send',
            'saraban.distribution.track',
            'saraban.report.view',
            'saraban.report.export',
        ]);
    }

    public function test_official_saraban_filename_generator_uses_moe_and_unit_code(): void
    {
        $service = app(OfficialSarabanFilenameService::class);

        $series = NumberSeries::create([
            'code' => 'out-test',
            'name' => 'หนังสือส่ง',
            'category' => 'outgoing',
            'prefix' => 'ศธ 06001',
        ]);

        $number = DocumentNumber::create([
            'uuid' => (string) Str::uuid(),
            'number_series_id' => $series->id,
            'buddhist_year' => 2567,
            'running_number' => 125,
            'formatted' => 'ศธ 06001/0125',
            'status' => DocumentNumber::STATUS_ISSUED,
        ]);

        $doc = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_OUTGOING,
            'subject' => 'ขอเชิญประชุมวิชาการ',
            'status' => Document::STATUS_SIGNED,
            'confidentiality' => Document::CONFIDENTIALITY_NORMAL,
            'urgency' => Document::URGENCY_NORMAL,
            'owner_org_unit_id' => $this->unit->id,
            'created_by' => $this->officer->id,
            'document_number_id' => $number->id,
        ]);

        // 1. ไฟล์หลัก
        $filename = $service->generate($doc);
        $this->assertSame('2567_MOE06001_125.pdf', $filename);

        // 2. สิ่งที่ส่งมาด้วย (Attachment 1, 2)
        $att1 = $service->generate($doc, 1);
        $this->assertSame('2567_MOE06001_125_1.pdf', $att1);

        $att2 = $service->generate($doc, 2);
        $this->assertSame('2567_MOE06001_125_2.pdf', $att2);
    }

    public function test_download_endpoint_returns_official_filename_in_content_disposition(): void
    {
        $series = NumberSeries::create([
            'code' => 'out-dl-test',
            'name' => 'หนังสือส่งดาวน์โหลด',
            'category' => 'outgoing',
            'prefix' => 'ศธ 06001',
        ]);

        $number = DocumentNumber::create([
            'uuid' => (string) Str::uuid(),
            'number_series_id' => $series->id,
            'buddhist_year' => 2567,
            'running_number' => 88,
            'formatted' => 'ศธ 06001/0088',
            'status' => DocumentNumber::STATUS_ISSUED,
        ]);

        $doc = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_OUTGOING,
            'subject' => 'ทดสอบดาวน์โหลด',
            'status' => Document::STATUS_SIGNED,
            'confidentiality' => Document::CONFIDENTIALITY_NORMAL,
            'urgency' => Document::URGENCY_NORMAL,
            'owner_org_unit_id' => $this->unit->id,
            'created_by' => $this->officer->id,
            'document_number_id' => $number->id,
        ]);

        Storage::disk('private_uploads')->put('saraban/docs/test88.pdf', 'dummy pdf content');

        $version = DocumentVersion::create([
            'document_id' => $doc->id,
            'version_no' => 1,
            'stage' => 'final',
            'pdf_file_path' => 'saraban/docs/test88.pdf',
            'file_hash' => hash('sha256', 'dummy pdf content'),
            'file_size' => 17,
            'created_by' => $this->officer->id,
        ]);

        $doc->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->officer)
            ->get("/api/saraban/documents/{$doc->id}/download");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('2567_MOE06001_88.pdf', $disposition);

        /*
         * ปลายทางนี้ยังไม่รู้จัก "สิ่งที่ส่งมาด้วย" รายไฟล์ — ต้องปฏิเสธ ไม่ใช่ส่ง
         * ตัวหนังสือออกไปโดยตั้งชื่อว่าเป็นเอกสารแนบลำดับที่ ๒
         */
        $this->actingAs($this->officer)
            ->get("/api/saraban/documents/{$doc->id}/download?attachment=2")
            ->assertNotFound();
    }

    public function test_cannot_electronically_distribute_top_secret_document(): void
    {
        $doc = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_OUTGOING,
            'subject' => 'เอกสารลับที่สุดระดับชาติ',
            'status' => Document::STATUS_SIGNED,
            'confidentiality' => 'top_secret',
            'urgency' => Document::URGENCY_NORMAL,
            'owner_org_unit_id' => $this->unit->id,
            'created_by' => $this->officer->id,
            'finalized_at' => now(),
        ]);

        Storage::disk('private_uploads')->put('saraban/docs/topsecret.pdf', 'secret');
        $version = DocumentVersion::create([
            'document_id' => $doc->id,
            'version_no' => 1,
            'stage' => 'final',
            'pdf_file_path' => 'saraban/docs/topsecret.pdf',
            'file_hash' => hash('sha256', 'secret'),
            'file_size' => 6,
            'created_by' => $this->officer->id,
        ]);
        $doc->update(['current_version_id' => $version->id]);

        $distributionService = app(DistributionService::class);

        // canDistribute ต้องเป็น false
        $this->assertFalse($distributionService->canDistribute($doc));

        // distribute ต้องโยน DistributionException พร้อมระบุข้อ ๒๙
        $this->expectException(DistributionException::class);
        $this->expectExceptionMessage('ลับที่สุด');

        $targetUser = User::factory()->create(['org_unit_id' => $this->unit->id]);
        $distributionService->distribute($doc, [
            ['type' => 'user', 'id' => $targetUser->id, 'role' => 'to'],
        ], $this->officer);
    }

    public function test_official_incoming_register_export_format(): void
    {
        $incomingType = DocumentType::create([
            'code' => 'incoming-test',
            'name' => 'หนังสือรับ',
            'abbreviation' => 'รส.',
            'category' => DocumentType::CATEGORY_INCOMING,
        ]);

        $series = NumberSeries::create([
            'code' => 'in-test-series',
            'name' => 'ทะเบียนรับ',
            'category' => 'incoming',
            'prefix' => 'รับ',
        ]);

        $regNumber = DocumentNumber::create([
            'uuid' => (string) Str::uuid(),
            'number_series_id' => $series->id,
            'buddhist_year' => 2567,
            'running_number' => 45,
            'formatted' => 'รับ/0045',
            'status' => DocumentNumber::STATUS_ISSUED,
        ]);

        $doc = Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $incomingType->id,
            'direction' => Document::DIRECTION_INCOMING,
            'subject' => 'เรื่องหนังสือรับจากภายนอก',
            'status' => Document::STATUS_REGISTERED,
            'confidentiality' => Document::CONFIDENTIALITY_NORMAL,
            'urgency' => Document::URGENCY_NORMAL,
            'owner_org_unit_id' => $this->unit->id,
            'created_by' => $this->officer->id,
        ]);

        IncomingRegistration::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $doc->id,
            'document_number_id' => $regNumber->id,
            'source_number' => 'ศธ 0201/999',
            'source_date' => '2024-05-10',
            'sender_name' => 'สพฐ.',
            'received_at' => now(),
            'registered_by' => $this->officer->id,
            'note' => 'ส่งต่อฝ่ายวิชาการ',
        ]);

        $exporter = app(RegisterReportExportService::class);
        $result = $exporter->exportRegister($this->officer, [
            'direction' => 'incoming',
        ], 'csv');

        $this->assertNotEmpty($result['content']);
        $this->assertStringContainsString('ทะเบียนรับที่', $result['content']);
        $this->assertStringContainsString('วัน เดือน ปี ที่รับ', $result['content']);
        $this->assertStringContainsString('จาก', $result['content']);
        $this->assertStringContainsString('ถึง', $result['content']);
        $this->assertStringContainsString('ที่ (เลขที่หนังสือ)', $result['content']);
        $this->assertStringContainsString('การปฏิบัติ', $result['content']);

        // ตรวจสอบว่ามีข้อมูลจริงปรากฏในแถว
        $this->assertStringContainsString('สพฐ.', $result['content']);
        $this->assertStringContainsString('ศธ 0201/999', $result['content']);
        $this->assertStringContainsString('เรื่องหนังสือรับจากภายนอก', $result['content']);
    }

    public function test_official_outgoing_register_export_format(): void
    {
        $series = NumberSeries::create([
            'code' => 'out-rep-test',
            'name' => 'ทะเบียนส่งออก',
            'category' => 'outgoing',
            'prefix' => 'ศธ 06001',
        ]);

        $num = DocumentNumber::create([
            'uuid' => (string) Str::uuid(),
            'number_series_id' => $series->id,
            'buddhist_year' => 2567,
            'running_number' => 77,
            'formatted' => 'ศธ 06001/0077',
            'status' => DocumentNumber::STATUS_ISSUED,
        ]);

        Document::create([
            'uuid' => (string) Str::uuid(),
            'document_type_id' => $this->type->id,
            'direction' => Document::DIRECTION_OUTGOING,
            'subject' => 'หนังสือส่งเรื่องจัดซื้อจัดจ้าง',
            'status' => Document::STATUS_SIGNED,
            'confidentiality' => Document::CONFIDENTIALITY_NORMAL,
            'urgency' => Document::URGENCY_NORMAL,
            'owner_org_unit_id' => $this->unit->id,
            'created_by' => $this->officer->id,
            'document_number_id' => $num->id,
        ]);

        $exporter = app(RegisterReportExportService::class);
        $result = $exporter->exportRegister($this->officer, [
            'direction' => 'outgoing',
        ], 'csv');

        $this->assertNotEmpty($result['content']);
        $this->assertStringContainsString('ทะเบียนส่งที่', $result['content']);
        $this->assertStringContainsString('ที่หนังสือ', $result['content']);
        $this->assertStringContainsString('วัน เดือน ปี ที่ส่ง', $result['content']);
        $this->assertStringContainsString('ศธ 06001/0077', $result['content']);
        $this->assertStringContainsString('หนังสือส่งเรื่องจัดซื้อจัดจ้าง', $result['content']);
    }
}
