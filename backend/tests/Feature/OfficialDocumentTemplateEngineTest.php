<?php

namespace Tests\Feature;

use App\Models\Document\DocumentFieldDefinition;
use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\DocumentEngine\PreviewSampleValues;
use App\Services\DocumentEngine\TemplateGeometry;
use App\Services\DocumentEngine\TemplatePreflight;
use App\Services\Saraban\OfficialDocumentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialDocumentTemplateEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_official_document_template_service_returns_four_standard_templates(): void
    {
        $service = new OfficialDocumentTemplateService;
        $templates = $service->getTemplates();

        $this->assertCount(4, $templates);

        $templateIds = array_column($templates, 'id');
        $this->assertContains('internal_memo', $templateIds);
        $this->assertContains('external_letter', $templateIds);
        $this->assertContains('college_order', $templateIds);
        $this->assertContains('college_announcement', $templateIds);

        // Verify Memo has left Garuda 1.5 cm
        $memo = collect($templates)->firstWhere('id', 'internal_memo');
        $this->assertNotNull($memo);
        $this->assertSame('left', $memo['garuda']['position']);
        $this->assertEquals(1.5, $memo['garuda']['size_cm']);

        // Verify External Letter has center Garuda 3.0 cm
        $external = collect($templates)->firstWhere('id', 'external_letter');
        $this->assertNotNull($external);
        $this->assertSame('center', $external['garuda']['position']);
        $this->assertEquals(3.0, $external['garuda']['size_cm']);

        // Verify College Order has center Garuda 3.0 cm
        $order = collect($templates)->firstWhere('id', 'college_order');
        $this->assertNotNull($order);
        $this->assertSame('center', $order['garuda']['position']);
        $this->assertEquals(3.0, $order['garuda']['size_cm']);

        // Verify College Announcement has center Garuda 3.0 cm
        $announcement = collect($templates)->firstWhere('id', 'college_announcement');
        $this->assertNotNull($announcement);
        $this->assertSame('center', $announcement['garuda']['position']);
        $this->assertEquals(3.0, $announcement['garuda']['size_cm']);
    }

    public function test_preview_sample_values_generates_thai_values_for_mappings(): void
    {
        DocumentFieldDefinition::create([
            'code' => 'saraban.document_number',
            'label_th' => 'เลขที่หนังสือ',
            'data_type' => 'TEXT',
            'is_active' => true,
        ]);

        DocumentFieldDefinition::create([
            'code' => 'personnel.full_name',
            'label_th' => 'ชื่อ-นามสกุล',
            'data_type' => 'TEXT',
            'is_active' => true,
        ]);

        $template = DocumentTemplate::create([
            'code' => 'TEST_PREVIEW_TPL',
            'name' => 'แบบฟอร์มทดสอบ',
            'content_html' => '<p>ทดสอบ</p>',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
            'is_active' => true,
        ]);

        $version = $template->currentVersion;

        $mapping1 = DocumentFieldMapping::create([
            'document_template_version_id' => $version->id,
            'field_code' => 'saraban.document_number',
            'field_type' => 'TEXT',
            'page' => 1,
            'x' => 10.0,
            'y' => 10.0,
            'width' => 50.0,
            'height' => 10.0,
            'is_active' => true,
        ]);

        $mapping2 = DocumentFieldMapping::create([
            'document_template_version_id' => $version->id,
            'field_code' => 'personnel.full_name',
            'field_type' => 'TEXT',
            'page' => 1,
            'x' => 10.0,
            'y' => 25.0,
            'width' => 80.0,
            'height' => 10.0,
            'is_active' => true,
        ]);

        $preview = new PreviewSampleValues;
        $values = $preview->forMappings([$mapping1, $mapping2]);

        $this->assertArrayHasKey('saraban.document_number', $values);
        $this->assertSame('ศธ ๐๖๐๑/๑๒๓๔', $values['saraban.document_number']);

        $this->assertArrayHasKey('personnel.full_name', $values);
        $this->assertSame('นางสาวกัญญาภัทร ศรีสุวรรณวิชัย', $values['personnel.full_name']);
    }

    public function test_document_field_mapping_supports_garuda_type(): void
    {
        $this->assertContains('GARUDA', DocumentFieldMapping::RENDERABLE_TYPES);

        $template = DocumentTemplate::create([
            'code' => 'TEST_MEMO_TPL',
            'name' => 'แม่แบบบันทึกข้อความทดสอบ',
            'description' => 'สำหรับการทดสอบ Field Mapping ครุฑ',
            'content_html' => '<p>บันทึกข้อความ</p>',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
            'is_active' => true,
        ]);

        $version = $template->currentVersion;

        $field = DocumentFieldMapping::create([
            'document_template_version_id' => $version->id,
            'field_code' => 'saraban.garuda',
            'field_type' => 'GARUDA',
            'page' => 1,
            'x' => 20.0,
            'y' => 25.0,
            'width' => 15.0,
            'height' => 15.0,
            'is_active' => true,
        ]);

        $this->assertSame('GARUDA', $field->field_type);
        $this->assertSame(20.0, (float) $field->x);
        $this->assertSame(25.0, (float) $field->y);
    }

    public function test_template_preflight_accepts_garuda_fields_without_size_warnings(): void
    {
        DocumentFieldDefinition::create([
            'code' => 'saraban.garuda',
            'label_th' => 'ตราครุฑ',
            'data_type' => 'IMAGE',
            'is_active' => true,
        ]);

        $template = DocumentTemplate::create([
            'code' => 'TPL_PREFLIGHT_TEST',
            'name' => 'แม่แบบทดสอบ Preflight',
            'content_html' => '<p>ทดสอบ</p>',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
            'is_active' => true,
        ]);

        $version = $template->currentVersion;

        DocumentFieldMapping::create([
            'document_template_version_id' => $version->id,
            'field_code' => 'saraban.garuda',
            'field_type' => 'GARUDA',
            'page' => 1,
            'x' => 20.0,
            'y' => 25.0,
            'width' => 15.0,
            'height' => 15.0,
            'is_active' => true,
        ]);

        $preflight = new TemplatePreflight(new TemplateGeometry);
        $result = $preflight->check($version);

        $this->assertEmpty($result['errors']);
    }

    public function test_garuda_asset_file_exists_on_backend_public_path(): void
    {
        $garudaPng = public_path('images/garuda.png');
        $this->assertFileExists($garudaPng);

        $garudaSvg = public_path('images/garuda.svg');
        $this->assertFileExists($garudaSvg);
    }
}
