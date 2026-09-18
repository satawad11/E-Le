<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\Saraban\Document;
use App\Models\Saraban\DocumentType;
use App\Models\User;
use App\Services\Saraban\DashboardService;
use App\Services\Saraban\OfficialEmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ชุดทดสอบระบบแม่แบบเอกสารราชการ ข้อความเกษียน ๔ ฝ่ายอาชีวะ
 * และบริการอีเมลสารบรรณตามระเบียบสำนักนายกรัฐมนตรีฯ
 */
class SarabanMasterSuiteTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $unit;

    private DocumentType $type;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => '06001',
            'name' => 'วิทยาลัยอาชีวศึกษา',
            'type' => 'department',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->type = DocumentType::create([
            'code' => 'memo',
            'name' => 'บันทึกข้อความ',
            'abbreviation' => 'บค.',
            'category' => DocumentType::CATEGORY_INTERNAL,
        ]);

        $this->user = User::factory()->create([
            'org_unit_id' => $this->unit->id,
        ]);
        $this->user->givePermissionTo([
            'saraban.document.view',
            'saraban.document.create',
        ]);
    }

    public function test_document_templates_endpoint_returns_official_templates_and_presets(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/saraban/templates');

        $response->assertOk();
        $response->assertJsonStructure([
            'templates' => [
                '*' => ['id', 'code', 'name', 'description', 'direction', 'garuda', 'sections'],
            ],
            'endorsement_presets' => [
                '*' => ['id', 'category', 'label', 'text'],
            ],
        ]);

        $templates = $response->json('templates');
        $this->assertCount(4, $templates);

        // ตรวจสอบบันทึกข้อความ ครุฑซ้าย ๑.๕ ซม.
        $memo = collect($templates)->firstWhere('id', 'internal_memo');
        $this->assertNotNull($memo);
        $this->assertSame('left', $memo['garuda']['position']);
        $this->assertEquals(1.5, $memo['garuda']['size_cm']);

        // ตรวจสอบหนังสือภายนอก ครุฑกลาง ๓ ซม.
        $external = collect($templates)->firstWhere('id', 'external_letter');
        $this->assertNotNull($external);
        $this->assertSame('center', $external['garuda']['position']);
        $this->assertEquals(3.0, $external['garuda']['size_cm']);

        // ตรวจสอบคำสั่งวิทยาลัย ครุฑกลาง ๓ ซม.
        $order = collect($templates)->firstWhere('id', 'college_order');
        $this->assertNotNull($order);
        $this->assertSame('center', $order['garuda']['position']);
        $this->assertEquals(3.0, $order['garuda']['size_cm']);

        // ตรวจสอบประกาศวิทยาลัย ครุฑกลาง ๓ ซม.
        $announcement = collect($templates)->firstWhere('id', 'college_announcement');
        $this->assertNotNull($announcement);
        $this->assertSame('center', $announcement['garuda']['position']);
        $this->assertEquals(3.0, $announcement['garuda']['size_cm']);

        // ตรวจสอบ Preset เกษียนด่วน ๔ ฝ่ายอาชีวศึกษา
        $presets = $response->json('endorsement_presets');
        $divisions = collect($presets)->pluck('division')->filter()->values()->all();
        $this->assertContains('resource_management', $divisions);
        $this->assertContains('academic_affairs', $divisions);
        $this->assertContains('student_affairs', $divisions);
        $this->assertContains('planning_and_cooperation', $divisions);
    }

    public function test_email_disclaimer_endpoint_returns_bilingual_disclaimer(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/saraban/templates/disclaimer?institution=วิทยาลัยเทคนิคตัวอย่าง');

        $response->assertOk();
        $response->assertJsonStructure([
            'disclaimer' => ['th', 'en', 'formatted'],
            'auto_reply_sample',
        ]);

        $disclaimer = $response->json('disclaimer');
        $this->assertStringContainsString('ข้อมูลราชการ', $disclaimer['th']);
        $this->assertStringContainsString('CONFIDENTIALITY NOTICE', $disclaimer['en']);

        $autoReply = $response->json('auto_reply_sample');
        $this->assertStringContainsString('วิทยาลัยเทคนิคตัวอย่าง', $autoReply);
        $this->assertStringContainsString('ระเบียบสำนักนายกรัฐมนตรี', $autoReply);
    }

    public function test_email_template_service_formats_urgent_subjects_correctly(): void
    {
        $service = app(OfficialEmailTemplateService::class);

        $mostUrgent = $service->formatEmailSubject('ขอเชิญประชุมคณะกรรมการ', 'most_urgent', 'ศธ ๐๖๐๐๑/๑๐');
        $this->assertSame('[ด่วนที่สุด] ที่ ศธ ๐๖๐๐๑/๑๐ ขอเชิญประชุมคณะกรรมการ', $mostUrgent);

        $veryUrgent = $service->formatEmailSubject('ส่งรายงานผลการดำเนินงาน', 'very_urgent');
        $this->assertSame('[ด่วนมาก] ส่งรายงานผลการดำเนินงาน', $veryUrgent);

        $urgent = $service->formatEmailSubject('ติดตามเรื่อง', 'urgent');
        $this->assertSame('[ด่วน] ติดตามเรื่อง', $urgent);

        $normal = $service->formatEmailSubject('แจ้งกำหนดการ', 'normal');
        $this->assertSame('แจ้งกำหนดการ', $normal);
    }

    public function test_dashboard_service_includes_urgency_and_direction_breakdowns(): void
    {
        // สร้างเอกสารด่วนที่สุด และปกติ
        Document::create([
            'uuid' => (string) Str::uuid(),
            'owner_org_unit_id' => $this->unit->id,
            'document_type_id' => $this->type->id,
            'direction' => 'internal',
            'subject' => 'บันทึกด่วนที่สุดเพื่อพิจารณา',
            'summary' => 'สรุปเรื่องด่วน',
            'confidentiality' => 'normal',
            'urgency' => 'most_urgent',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        Document::create([
            'uuid' => (string) Str::uuid(),
            'owner_org_unit_id' => $this->unit->id,
            'document_type_id' => $this->type->id,
            'direction' => 'outgoing',
            'subject' => 'หนังสือปกติส่งภายนอก',
            'summary' => 'สรุปเรื่อง',
            'confidentiality' => 'normal',
            'urgency' => 'normal',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $service = app(DashboardService::class);
        $summary = $service->summaryFor($this->user);

        $this->assertArrayHasKey('direction_stats', $summary);
        $this->assertArrayHasKey('urgency_stats', $summary);

        $this->assertSame(1, $summary['direction_stats']['internal']);
        $this->assertSame(1, $summary['direction_stats']['outgoing']);
        $this->assertSame(0, $summary['direction_stats']['incoming']);

        $this->assertSame(1, $summary['urgency_stats']['most_urgent']);
        $this->assertSame(1, $summary['urgency_stats']['normal']);
    }
}
