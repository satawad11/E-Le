<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use App\Services\DocumentEngine\TemplateGeometry;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DESIGNER-01 — ระบบพิกัดของหน้าออกแบบต้องเป็นขนาดหน้าจริงของไฟล์ต้นฉบับ
 *
 * เดิมหน้าออกแบบตรึงกระดาษไว้ที่ A4 แนวตั้ง (595×842) เสมอ ขณะที่ `PdfOverlayRenderer`
 * วางฟิลด์บนขนาดหน้าจริงที่นำเข้า ฟิลด์ที่ "ดูตรง" บนจอจึงพิมพ์ออกมาผิดตำแหน่งได้
 * และฟิลด์ที่เลยขอบกระดาษจะหายไปเงียบ ๆ ตอนพิมพ์โดยไม่มีอะไรเตือน
 */
class DocumentDesignerGeometryTest extends TestCase
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

    private function draftWithForm(string $code = 'geometry_form'): DocumentTemplateVersion
    {
        $path = resource_path('document-templates/leave_vacation.pdf');

        if (! is_file($path)) {
            $this->markTestSkipped('ไม่พบแบบฟอร์มต้นฉบับสำหรับทดสอบ');
        }

        $template = DocumentTemplate::create([
            'code' => $code, 'name' => 'แบบฟอร์มทดสอบพิกัด', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]);

        $stored = 'document-templates/'.$code.'.pdf';
        Storage::disk('private_uploads')->put($stored, (string) file_get_contents($path));

        $version = $template->versions()->latest('version')->first();
        $version->update([
            'status' => DocumentTemplateVersion::STATUS_DRAFT,
            'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'pdf_file_path' => $stored,
            'page_count' => 1,
            'is_active' => false,
        ]);

        return $version->fresh();
    }

    /** @return array<string, mixed> */
    private function mapping(array $overrides = []): array
    {
        return array_merge([
            'field_code' => 'employee.full_name', 'field_type' => 'TEXT', 'page' => 1,
            'x' => 100, 'y' => 150, 'width' => 200, 'height' => 20, 'font_size' => 14, 'align' => 'left',
        ], $overrides);
    }

    public function test_the_designer_is_told_the_real_size_of_every_page(): void
    {
        $version = $this->draftWithForm();

        $response = $this->actingAs($this->admin())
            ->getJson("/api/admin/document-engine/versions/{$version->id}/geometry")
            ->assertOk()
            ->assertJsonStructure(['page_count', 'pages' => [['page', 'width', 'height']]]);

        $pages = $response->json('pages');

        $this->assertSame(count($pages), $response->json('page_count'));
        $this->assertGreaterThan(0, $pages[0]['width']);
        $this->assertGreaterThan(0, $pages[0]['height']);

        // ค่าเดียวกับที่ตัวเรนเดอร์ใช้จริง ไม่ใช่ค่าคงที่ที่หน้าจอเดาเอง
        $sizes = app(TemplateGeometry::class)->pageSizes($version);
        $this->assertEqualsWithDelta($sizes[0]['width'], $pages[0]['width'], 0.01);
        $this->assertEqualsWithDelta($sizes[0]['height'], $pages[0]['height'], 0.01);
    }

    public function test_a_field_placed_outside_the_paper_cannot_be_saved(): void
    {
        $version = $this->draftWithForm('outside_form');
        $size = app(TemplateGeometry::class)->pageSizes($version)[0];

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->mapping(['x' => $size['width'] - 10, 'width' => 200])],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.mappings.0', fn (string $message) => str_contains($message, 'นอกขอบกระดาษ'));

        $this->assertSame(0, $version->fieldMappings()->count(), 'ตำแหน่งที่ไม่ผ่านการตรวจต้องไม่ถูกบันทึกแม้บางส่วน');
    }

    public function test_a_field_on_a_page_that_does_not_exist_cannot_be_saved(): void
    {
        $version = $this->draftWithForm('missing_page_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->mapping(['page' => 9])],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.mappings.0', fn (string $message) => str_contains($message, 'หน้า 9'));
    }

    public function test_a_field_inside_the_paper_is_saved_as_before(): void
    {
        $version = $this->draftWithForm('inside_form');

        $this->actingAs($this->admin())
            ->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
                'mappings' => [$this->mapping()],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'mappings');
    }

    public function test_a_version_with_fields_outside_the_paper_cannot_be_published(): void
    {
        $version = $this->draftWithForm('publish_guard_form');
        $size = app(TemplateGeometry::class)->pageSizes($version)[0];

        // ตำแหน่งที่มาจากการนำเข้า/คัดลอกเวอร์ชัน ไม่ได้ผ่านหน้าออกแบบ จึงต้องถูกตรวจซ้ำตอนเผยแพร่
        $version->fieldMappings()->create($this->mapping(['y' => $size['height'] + 50]) + [
            'overflow' => 'shrink', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/document-engine/versions/{$version->id}/publish")
            ->assertStatus(422);

        $this->assertSame(
            DocumentTemplateVersion::STATUS_DRAFT,
            $version->fresh()->status,
            'เวอร์ชันที่ตรวจไม่ผ่านต้องคงเป็นฉบับร่าง',
        );
    }

    public function test_a_version_without_a_source_file_is_not_blocked_by_the_geometry_check(): void
    {
        $template = DocumentTemplate::create([
            'code' => 'html_only_form', 'name' => 'แบบฟอร์ม HTML', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_HTML,
            'content_html' => '<p>x</p>', 'is_active' => true,
        ]);

        $version = $template->versions()->latest('version')->first();

        $this->assertSame([], app(TemplateGeometry::class)->pageSizes($version));
        $this->assertSame([], app(TemplateGeometry::class)->problems($version, [$this->mapping(['x' => 9000])]));
    }
}
