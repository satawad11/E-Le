<?php

namespace Tests\Feature;

use App\Models\Document\DocumentFieldMapping;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use App\Services\DocumentEngine\PdfOverlayRenderer;
use App\Services\DocumentEngine\ValueFormatter;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DESIGNER-02 — คุณสมบัติของฟิลด์ที่ตั้งได้จากหน้าออกแบบต้องมีผลจริงกับไฟล์ที่พิมพ์
 *
 * เดิมคอลัมน์ `format`, `options`, `font`, `overflow` บันทึกได้แต่หน้าออกแบบไม่มีช่องให้ตั้ง
 * และ `format` ไม่เคยถูกอ่านโดยตัวเรนเดอร์เลย ส่วนชนิด BARCODE เลือกได้ทั้งที่วาดไม่ได้
 * จึงถูกพิมพ์ออกมาเป็นข้อความธรรมดาโดยไม่มีอะไรเตือน
 */
class DocumentFieldFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function draft(string $code): DocumentTemplateVersion
    {
        $source = resource_path('document-templates/leave_vacation.pdf');

        if (! is_file($source)) {
            $this->markTestSkipped('ไม่พบแบบฟอร์มต้นฉบับสำหรับทดสอบ');
        }

        $template = DocumentTemplate::create([
            'code' => $code, 'name' => 'แบบฟอร์มทดสอบรูปแบบค่า', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]);

        $stored = 'document-templates/'.$code.'.pdf';
        Storage::disk('private_uploads')->put($stored, (string) file_get_contents($source));

        $version = $template->versions()->latest('version')->first();
        $version->update([
            'status' => DocumentTemplateVersion::STATUS_DRAFT,
            'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'pdf_file_path' => $stored, 'page_count' => 1, 'is_active' => false,
        ]);

        return $version->fresh();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'field_code' => 'leave.start_date', 'field_type' => 'DATE', 'page' => 1,
            'x' => 100, 'y' => 150, 'width' => 200, 'height' => 20, 'font_size' => 14, 'align' => 'left',
        ], $overrides);
    }

    // ───────────────────────────── ValueFormatter ─────────────────────────────

    public function test_dates_are_rendered_in_the_thai_format_the_admin_chose(): void
    {
        $formatter = app(ValueFormatter::class);

        $this->assertSame('1 ตุลาคม 2569', $formatter->format('2026-10-01', 'thai_date'));
        $this->assertSame('1 ต.ค. 2569', $formatter->format('2026-10-01', 'thai_date_short'));
        $this->assertSame('01/10/2569', $formatter->format('2026-10-01', 'date_be'));
        $this->assertSame('01/10/2026', $formatter->format('2026-10-01', 'date_ce'));
    }

    public function test_numbers_and_thai_digits_follow_the_chosen_format(): void
    {
        $formatter = app(ValueFormatter::class);

        $this->assertSame('1,235', $formatter->format('1234.56', 'number'));
        $this->assertSame('1,234.56', $formatter->format('1234.56', 'number_2'));
        $this->assertSame('๑๒๓', $formatter->format('123', 'thai_digits'));
    }

    public function test_a_value_that_cannot_be_formatted_is_printed_as_it_is(): void
    {
        $formatter = app(ValueFormatter::class);

        // ฟิลด์วันที่ของระบบเดิมส่งข้อความไทยมาแล้ว การคืนค่าเดิมคือพฤติกรรมที่ถูกต้อง
        $this->assertSame('1 ตุลาคม 2569', $formatter->format('1 ตุลาคม 2569', 'thai_date'));
        $this->assertSame('ไม่ใช่ตัวเลข', $formatter->format('ไม่ใช่ตัวเลข', 'number'));
        $this->assertSame('ค่าเดิม', $formatter->format('ค่าเดิม', 'รูปแบบที่ไม่มีจริง'));
        $this->assertSame('', $formatter->format(null, 'thai_date'));
    }

    // ───────────────────────────── การบันทึกจากหน้าออกแบบ ─────────────────────────────

    public function test_the_designer_can_save_every_property_the_renderer_understands(): void
    {
        $version = $this->draft('properties_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->payload([
                    'font' => 'THSarabunNewB',
                    'overflow' => 'clip',
                    'format' => 'thai_date',
                    'static_text' => 'ข้อความคงที่',
                    'options' => ['underline' => true, 'letter_spacing' => 2.5, 'rotate' => 90],
                ])],
            ])
            ->assertOk();

        $mapping = $version->fieldMappings()->firstOrFail();

        $this->assertSame('THSarabunNewB', $mapping->font);
        $this->assertSame('clip', $mapping->overflow);
        $this->assertSame('thai_date', $mapping->format);
        $this->assertSame('ข้อความคงที่', $mapping->static_text);
        $this->assertSame(['underline' => true, 'letter_spacing' => 2.5, 'rotate' => 90], $mapping->options);
    }

    public function test_an_unknown_format_is_rejected_instead_of_being_stored_and_ignored(): void
    {
        $version = $this->draft('unknown_format_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->payload(['format' => 'd/m/Y แบบเดิม'])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mappings.0.format');
    }

    public function test_an_unknown_option_key_is_rejected(): void
    {
        $version = $this->draft('unknown_option_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->payload(['options' => ['สีตัวอักษร' => 'แดง']])],
            ])
            ->assertStatus(422);
    }

    public function test_a_field_type_the_renderer_cannot_draw_is_rejected(): void
    {
        $version = $this->draft('barcode_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->payload(['field_type' => 'BARCODE'])],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mappings.0.field_type');

        $this->assertNotContains('BARCODE', DocumentFieldMapping::RENDERABLE_TYPES);
    }

    public function test_the_designer_is_given_the_formats_and_types_it_may_offer(): void
    {
        $version = $this->draft('catalog_form');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/admin/document-engine/versions/{$version->id}/mappings")
            ->assertOk();

        $this->assertSame(DocumentFieldMapping::RENDERABLE_TYPES, $response->json('field_types'));
        $this->assertArrayHasKey('thai_date', $response->json('formats'));
    }

    // ───────────────────────────── การเรนเดอร์จริง ─────────────────────────────

    public function test_a_form_with_qr_rotation_underline_and_spacing_is_printed_without_failing(): void
    {
        $version = $this->draft('rich_form');

        $version->fieldMappings()->create($this->payload([
            'field_code' => 'leave.document_number', 'field_type' => 'TEXT', 'format' => 'thai_digits',
            'options' => ['underline' => true, 'letter_spacing' => 2, 'rotate' => 90], 'is_active' => true,
        ]));
        $version->fieldMappings()->create($this->payload([
            'field_code' => 'leave.verification_url', 'field_type' => 'QR_CODE',
            'x' => 400, 'y' => 700, 'width' => 80, 'height' => 80, 'is_active' => true,
        ]));

        $content = app(PdfOverlayRenderer::class)->render($version->fresh(), [
            'leave.document_number' => '123/2569',
            'leave.verification_url' => 'https://example.test/verify-leave/abc',
        ]);

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertGreaterThan(1000, strlen($content));
    }

    public function test_a_field_with_a_condition_is_printed_only_when_the_condition_holds(): void
    {
        $version = $this->draft('conditional_form');

        $version->fieldMappings()->create($this->payload([
            'field_code' => 'leave.reason', 'field_type' => 'TEXT',
            'options' => ['visible_when' => ['field' => 'leave.type', 'operator' => 'equals', 'value' => 'ลาป่วย']],
            'is_active' => true,
        ]));

        $renderer = app(PdfOverlayRenderer::class);
        $printed = $renderer->render($version->fresh(), ['leave.type' => 'ลาป่วย', 'leave.reason' => 'ป่วยเป็นไข้']);
        $skipped = $renderer->render($version->fresh(), ['leave.type' => 'ลาพักผ่อน', 'leave.reason' => 'ป่วยเป็นไข้']);

        $this->assertStringStartsWith('%PDF', $printed);
        $this->assertNotSame(strlen($skipped), strlen($printed), 'ฟิลด์ที่เงื่อนไขไม่ผ่านต้องไม่ถูกพิมพ์');
    }

    public function test_a_condition_with_an_operator_outside_the_whitelist_is_rejected(): void
    {
        $version = $this->draft('bad_condition_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->payload([
                    'options' => ['visible_when' => ['field' => 'leave.type', 'operator' => 'regex', 'value' => '.*']],
                ])],
            ])
            ->assertStatus(422);
    }

    public function test_a_checkbox_is_ticked_only_when_the_value_matches_what_the_admin_listed(): void
    {
        $version = $this->draft('checkbox_values_form');

        $version->fieldMappings()->create($this->payload([
            'field_code' => 'leave.type', 'field_type' => 'CHECKBOX',
            'width' => 12, 'height' => 12,
            'options' => ['mark' => 'X', 'checked_values' => ['ลาป่วย']], 'is_active' => true,
        ]));

        $renderer = app(PdfOverlayRenderer::class);
        $ticked = $renderer->render($version->fresh(), ['leave.type' => 'ลาป่วย']);
        $empty = $renderer->render($version->fresh(), ['leave.type' => 'ลากิจส่วนตัว']);

        $this->assertStringStartsWith('%PDF', $ticked);
        $this->assertNotSame(strlen($empty), strlen($ticked), 'ค่าที่ไม่อยู่ในรายการต้องไม่ถูกติ๊ก');
    }
}
