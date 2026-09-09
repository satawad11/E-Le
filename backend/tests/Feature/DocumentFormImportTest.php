<?php

namespace Tests\Feature;

use App\Models\Document\DocumentBindingRule;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGNER-05 — นำไฟล์แบบฟอร์มราชการเข้าเป็น "ฉบับร่าง" ของแม่แบบที่มีอยู่แล้ว
 *
 * คำสั่งรุ่นเดิมสร้างแม่แบบชุดใหม่ชื่อขึ้นต้น `form_*` ขนานกับแม่แบบเดิมที่ผูกกับประเภท
 * การลาอยู่แล้ว ทำให้เกิดแม่แบบสองชุดของแบบฟอร์มเดียวกัน ซึ่ง DOC-ENGINE-09 เคยตาม
 * ยุบรวมไปแล้วครั้งหนึ่ง เทสต์ชุดนี้ตรึงพฤติกรรมใหม่ไว้ และตรึงว่า "การนำเข้าต้องไม่
 * เปลี่ยนเอกสารที่ออกอยู่ทุกวันนี้แม้แต่ฉบับเดียว"
 */
class DocumentFormImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(resource_path('document-templates/leave_vacation.pdf'))) {
            $this->markTestSkipped('ไม่พบไฟล์แบบฟอร์มต้นฉบับในโครงการ');
        }
    }

    private function template(string $code, string $renderMode = DocumentTemplate::RENDER_HTML): DocumentTemplate
    {
        return DocumentTemplate::create([
            'code' => $code, 'name' => 'แม่แบบเดิม '.$code, 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => $renderMode,
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'content_html' => '<p>แบบฟอร์มเดิมของ '.$code.'</p>', 'is_active' => true,
        ]);
    }

    public function test_it_attaches_the_pdf_to_the_existing_template_instead_of_making_a_second_one(): void
    {
        $template = $this->template('leave_vacation');

        $this->artisan('document:import-forms')->assertSuccessful();

        $this->assertDatabaseMissing('document_templates', ['code' => 'form_leave_vacation']);

        $draft = $template->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->latest('version')->firstOrFail();

        $this->assertSame(DocumentTemplate::RENDER_PDF_OVERLAY, $draft->render_mode);
        $this->assertTrue(Storage::disk('private_uploads')->exists($draft->pdf_file_path));
        $this->assertGreaterThan(0, $draft->page_count);
    }

    public function test_it_does_not_change_the_form_people_print_today(): void
    {
        $template = $this->template('leave_vacation');
        $active = $template->versions()->where('status', DocumentTemplateVersion::STATUS_ACTIVE)->firstOrFail();

        $this->artisan('document:import-forms')->assertSuccessful();

        $active->refresh();
        $template->refresh();

        $this->assertSame(DocumentTemplateVersion::STATUS_ACTIVE, $active->status);
        $this->assertSame('<p>แบบฟอร์มเดิมของ leave_vacation</p>', $active->content_html);
        $this->assertNull($active->pdf_file_path, 'เวอร์ชันที่ใช้งานอยู่ต้องไม่ถูกแตะ');
        $this->assertSame(
            DocumentTemplate::RENDER_HTML,
            $template->render_mode,
            'แม่แบบยังต้องเป็น html จนกว่าจะมีการเผยแพร่ฉบับร่าง',
        );
    }

    public function test_it_fills_an_empty_draft_instead_of_stacking_another_one(): void
    {
        $template = $this->template('leave_vacation');

        // ฉบับร่างเปล่าที่ผู้ดูแลกดสร้างค้างไว้ หรือที่เกิดจากการเติมเวอร์ชัน (LS-08)
        $empty = $template->versions()->create([
            'version' => 2, 'status' => DocumentTemplateVersion::STATUS_DRAFT,
            'render_mode' => DocumentTemplate::RENDER_HTML,
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
            'content_html' => '', 'effective_from' => now()->toDateString(), 'is_active' => false,
        ]);

        $this->artisan('document:import-forms')->assertSuccessful();

        $this->assertSame(
            2,
            $template->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->count() + 1,
            'ต้องไม่เกิดฉบับร่างซ้อนอีกใบ',
        );

        $empty->refresh();

        $this->assertNotNull($empty->pdf_file_path);
        $this->assertSame(DocumentTemplate::RENDER_PDF_OVERLAY, $empty->render_mode);
    }

    public function test_running_it_twice_does_not_overwrite_work_in_progress(): void
    {
        $template = $this->template('leave_vacation');

        $this->artisan('document:import-forms')->assertSuccessful();

        $draft = $template->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->latest('version')->firstOrFail();
        $draft->fieldMappings()->create([
            'field_code' => 'employee.full_name', 'field_type' => 'TEXT', 'page' => 1,
            'x' => 100, 'y' => 150, 'width' => 200, 'height' => 20, 'font_size' => 14,
            'align' => 'left', 'overflow' => 'shrink', 'is_active' => true,
        ]);

        $this->artisan('document:import-forms')->assertSuccessful();

        $this->assertSame(1, $template->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->count());
        $this->assertSame(1, $draft->fresh()->fieldMappings()->count(), 'ตำแหน่งฟิลด์ที่วางไว้ต้องไม่หาย');
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $template = $this->template('leave_vacation');

        $this->artisan('document:import-forms', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $template->versions()->where('status', DocumentTemplateVersion::STATUS_DRAFT)->count());
        $this->assertDatabaseMissing('document_templates', ['code' => 'leave_cancel']);
    }

    public function test_it_creates_the_template_with_the_plain_code_when_missing(): void
    {
        $this->artisan('document:import-forms')->assertSuccessful();

        $this->assertDatabaseHas('document_templates', ['code' => 'leave_cancel']);
        $this->assertDatabaseMissing('document_templates', ['code' => 'form_leave_cancel']);
    }

    public function test_binding_points_leave_types_at_the_existing_template(): void
    {
        $template = $this->template('leave_vacation');
        $type = LeaveType::create(['name' => 'ลาพักผ่อน', 'code_prefix' => 'V']);

        $this->artisan('document:import-forms', ['--bind' => true])->assertSuccessful();

        $this->assertDatabaseHas('document_binding_rules', [
            'module' => 'LEAVE',
            'entity_type' => 'LEAVE_TYPE',
            'entity_id' => $type->id,
            'document_template_id' => $template->id,
            'is_active' => true,
        ]);

        $cancel = DocumentTemplate::where('code', 'leave_cancel')->firstOrFail();

        $this->assertTrue(
            DocumentBindingRule::where('entity_type', 'LEAVE_CANCELLATION')
                ->whereNull('entity_id')
                ->where('document_template_id', $cancel->id)
                ->exists(),
            'แบบฟอร์มยกเลิกต้องผูกครอบทุกประเภทการลา',
        );
    }

    public function test_it_warns_when_the_old_duplicate_templates_still_exist(): void
    {
        $this->template('form_leave_vacation', DocumentTemplate::RENDER_PDF_OVERLAY);

        $this->artisan('document:import-forms', ['--dry-run' => true])
            ->expectsOutputToContain('form_leave_vacation')
            ->assertSuccessful();
    }
}
