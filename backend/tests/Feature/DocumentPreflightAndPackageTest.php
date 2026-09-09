<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DocumentEngine\PrintCalibrationSheet;
use App\Services\DocumentEngine\TemplatePackage;
use App\Services\DocumentEngine\TemplatePreflight;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DESIGNER-04 — ตรวจก่อนเผยแพร่ พรีวิวที่ใช้ตรวจได้จริง สอบเทียบการพิมพ์ เทียบเวอร์ชัน และย้ายแบบฟอร์ม
 *
 * เดิมกดเผยแพร่ได้เสมอ ความผิดพลาดจึงไปโผล่ตอนผู้ใช้พิมพ์เอกสารจริง พรีวิวใส่ "ชื่อโค้ดของฟิลด์"
 * ลงในช่องจึงตรวจความพอดีไม่ได้ ค่าชดเชยเครื่องพิมพ์ต้องเดา และการย้ายแบบฟอร์มข้ามเครื่อง
 * ทำได้เฉพาะผ่านคำสั่งของผู้พัฒนา
 */
class DocumentPreflightAndPackageTest extends TestCase
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

    private function draft(string $code, string $status = DocumentTemplateVersion::STATUS_DRAFT): DocumentTemplateVersion
    {
        $source = resource_path('document-templates/leave_vacation.pdf');

        if (! is_file($source)) {
            $this->markTestSkipped('ไม่พบแบบฟอร์มต้นฉบับสำหรับทดสอบ');
        }

        $template = DocumentTemplate::create([
            'code' => $code, 'name' => 'แบบฟอร์มทดสอบก่อนเผยแพร่', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]);

        $stored = 'document-templates/'.$code.'.pdf';
        Storage::disk('private_uploads')->put($stored, (string) file_get_contents($source));

        $version = $template->versions()->latest('version')->first();
        $version->update([
            'status' => $status,
            'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'pdf_file_path' => $stored,
            'page_count' => 1,
            'is_active' => $status === DocumentTemplateVersion::STATUS_ACTIVE,
        ]);

        return $version->fresh();
    }

    private function map(DocumentTemplateVersion $version, array $overrides = []): void
    {
        $version->fieldMappings()->create(array_merge([
            'field_code' => 'employee.full_name', 'field_type' => 'TEXT', 'page' => 1,
            'x' => 100, 'y' => 150, 'width' => 200, 'height' => 20, 'font_size' => 14,
            'align' => 'left', 'overflow' => 'shrink', 'is_active' => true,
        ], $overrides));
    }

    // ───────────────────────────── ตรวจก่อนเผยแพร่ ─────────────────────────────

    public function test_a_field_pointing_at_a_variable_that_does_not_exist_blocks_publishing(): void
    {
        $version = $this->draft('unknown_variable_form');
        $this->map($version, ['field_code' => 'employee.ไม่มีตัวแปรนี้']);

        $result = app(TemplatePreflight::class)->check($version->fresh());

        $this->assertNotEmpty($result['errors']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/document-engine/versions/{$version->id}/publish")
            ->assertStatus(422);

        $this->assertSame(DocumentTemplateVersion::STATUS_DRAFT, $version->fresh()->status);
    }

    public function test_static_text_does_not_need_a_variable_to_exist(): void
    {
        $version = $this->draft('static_text_form');
        $this->map($version, ['field_code' => 'label.only', 'static_text' => 'เรียน ผู้อำนวยการ']);

        $this->assertSame([], app(TemplatePreflight::class)->check($version->fresh())['errors']);
    }

    public function test_overlapping_and_tiny_fields_are_warnings_not_blockers(): void
    {
        $version = $this->draft('overlap_form');
        $this->map($version);
        $this->map($version, ['field_code' => 'employee.position', 'x' => 120, 'y' => 155, 'height' => 4]);

        $result = app(TemplatePreflight::class)->check($version->fresh());

        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['warnings']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/document-engine/versions/{$version->id}/publish")
            ->assertOk();
    }

    public function test_the_designer_can_ask_for_the_check_before_pressing_publish(): void
    {
        $version = $this->draft('preflight_endpoint_form');
        $this->map($version);

        $this->actingAs($this->admin())
            ->getJson("/api/admin/document-engine/versions/{$version->id}/preflight")
            ->assertOk()
            ->assertJsonStructure(['errors', 'warnings']);
    }

    public function test_an_empty_form_is_reported_as_a_warning(): void
    {
        $version = $this->draft('empty_form');

        $this->assertNotEmpty(app(TemplatePreflight::class)->check($version)['warnings']);
    }

    // ───────────────────────────── พรีวิวสามโหมด ─────────────────────────────

    public function test_the_sample_preview_uses_realistic_thai_text(): void
    {
        $version = $this->draft('sample_preview_form');
        $this->map($version);

        $response = $this->actingAs($this->admin())
            ->get("/api/admin/document-engine/versions/{$version->id}/preview?mode=sample")
            ->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_blank_preview_draws_only_the_boxes(): void
    {
        $version = $this->draft('blank_preview_form');
        $this->map($version);

        $sample = $this->actingAs($this->admin())->get("/api/admin/document-engine/versions/{$version->id}/preview?mode=sample");
        $blank = $this->actingAs($this->admin())->get("/api/admin/document-engine/versions/{$version->id}/preview?mode=blank");

        $sample->assertOk();
        $blank->assertOk();
        $this->assertNotSame($sample->getContent(), $blank->getContent(), 'โหมดโครงเปล่าต้องได้เอกสารคนละแบบกับพรีวิวปกติ');
    }

    /** @return array{0: Leave, 1: User} */
    private function approvedLeave(): array
    {
        $department = Department::create(['name' => 'แผนกทดสอบพรีวิว']);
        $owner = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาพักผ่อน', 'code_prefix' => 'V']);

        $leave = Leave::create([
            'user_id' => $owner->id, 'leave_type_id' => $type->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDay()->toDateString(),
            'total_days' => 2, 'reason' => 'พักผ่อนประจำปี', 'status' => 'Approved', 'approval_level' => 1,
        ]);

        LeaveApproval::create([
            'leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1,
            'step_name' => 'ผู้อำนวยการ', 'status' => 'Approved',
            'approver_name' => 'นายทดสอบ อนุมัติ', 'approver_position' => 'ผู้อำนวยการ', 'approved_at' => now(),
        ]);

        return [$leave->fresh(), $owner];
    }

    public function test_previewing_with_a_real_leave_is_allowed_and_recorded(): void
    {
        $version = $this->draft('real_preview_form');
        $this->map($version);
        [$leave] = $this->approvedLeave();

        $this->actingAs($this->admin())
            ->get("/api/admin/document-engine/versions/{$version->id}/preview?mode=leave&leave_id={$leave->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_preview_with_real_data',
            'resource_id' => (string) $leave->id,
        ]);
    }

    public function test_managing_templates_does_not_grant_the_right_to_read_someone_elses_leave(): void
    {
        $version = $this->draft('guarded_preview_form');
        $this->map($version);
        [$leave] = $this->approvedLeave();

        // มีสิทธิ์จัดการแม่แบบ แต่ไม่มีสิทธิ์เปิดใบลาของผู้อื่น
        $designer = User::factory()->create(['is_active' => true]);
        $designer->givePermissionTo(Permission::findOrCreate('document.template.manage', 'web'));

        $this->actingAs($designer)
            ->get("/api/admin/document-engine/versions/{$version->id}/preview?mode=leave&leave_id={$leave->id}")
            ->assertForbidden();
    }

    // ───────────────────────────── ใบสอบเทียบการพิมพ์ ─────────────────────────────

    public function test_the_calibration_sheet_is_printable(): void
    {
        $version = $this->draft('calibration_sheet_form');

        $response = $this->actingAs($this->admin())
            ->get("/api/admin/document-engine/versions/{$version->id}/calibration-sheet")
            ->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_perfect_printout_needs_no_correction(): void
    {
        $sheet = app(PrintCalibrationSheet::class);

        $expectedOrigin = PrintCalibrationSheet::ORIGIN_X / 72 * 25.4;   // 25.4 มม.
        $expectedSpan = PrintCalibrationSheet::SPAN / 72 * 25.4;         // 127 มม.

        $result = $sheet->solve($expectedOrigin, $expectedOrigin, $expectedSpan, $expectedSpan);

        $this->assertEqualsWithDelta(0, $result['x_offset'], 0.05);
        $this->assertEqualsWithDelta(0, $result['y_offset'], 0.05);
        $this->assertEqualsWithDelta(1, $result['scale'], 0.001);
    }

    public function test_a_shifted_printout_produces_the_offset_that_cancels_it(): void
    {
        $sheet = app(PrintCalibrationSheet::class);

        $expectedOrigin = PrintCalibrationSheet::ORIGIN_X / 72 * 25.4;
        $expectedSpan = PrintCalibrationSheet::SPAN / 72 * 25.4;

        // เครื่องพิมพ์เลื่อนทุกอย่างไปทางขวา 2 มม. และลง 1 มม. โดยขนาดไม่เพี้ยน
        $result = $sheet->solve($expectedOrigin + 2, $expectedOrigin + 1, $expectedSpan, $expectedSpan);

        $this->assertEqualsWithDelta(-5.67, $result['x_offset'], 0.1);   // 2 มม. ≈ 5.67 pt
        $this->assertEqualsWithDelta(-2.83, $result['y_offset'], 0.1);
        $this->assertEqualsWithDelta(1, $result['scale'], 0.001);
    }

    public function test_the_screen_can_ask_the_server_to_compute_the_correction(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/document-engine/calibrations/solve', [
                'measured_a_x_mm' => 25.4, 'measured_a_y_mm' => 25.4,
                'measured_ab_mm' => 127, 'measured_ac_mm' => 127,
            ])
            ->assertOk()
            ->assertJsonStructure(['calibration' => ['x_offset', 'y_offset', 'scale']]);
    }

    // ───────────────────────────── เทียบเวอร์ชัน ─────────────────────────────

    public function test_the_diff_shows_what_changed_between_two_versions(): void
    {
        $old = $this->draft('diff_form', DocumentTemplateVersion::STATUS_ACTIVE);
        $this->map($old);
        $this->map($old, ['field_code' => 'employee.position', 'y' => 200]);

        $new = $this->actingAs($this->admin())
            ->postJson("/api/admin/document-engine/templates/{$old->document_template_id}/versions", ['from_version_id' => $old->id])
            ->assertCreated()->json('version');

        $version = DocumentTemplateVersion::find($new['id']);
        $version->fieldMappings()->where('field_code', 'employee.position')->delete();
        $version->fieldMappings()->where('field_code', 'employee.full_name')->update(['x' => 180]);
        $this->map($version, ['field_code' => 'leave.reason']);

        $response = $this->actingAs($this->admin())
            ->getJson("/api/admin/document-engine/versions/{$version->id}/diff?against={$old->id}")
            ->assertOk();

        $this->assertContains('leave.reason#1', $response->json('added'));
        $this->assertContains('employee.position#1', $response->json('removed'));
        $this->assertSame('employee.full_name', $response->json('changed.0.field_code'));
        $this->assertSame(180.0, (float) $response->json('changed.0.changes.x.to'));
    }

    public function test_two_versions_of_different_templates_cannot_be_compared(): void
    {
        $a = $this->draft('diff_a_form');
        $b = $this->draft('diff_b_form');

        $this->actingAs($this->admin())
            ->getJson("/api/admin/document-engine/versions/{$a->id}/diff?against={$b->id}")
            ->assertStatus(422);
    }

    // ───────────────────────────── นำเข้า/ส่งออกชุดแบบฟอร์ม ─────────────────────────────

    public function test_a_form_can_be_exported_and_imported_on_another_installation(): void
    {
        $version = $this->draft('portable_form');
        $this->map($version, ['x' => 111, 'y' => 222, 'format' => 'thai_date', 'options' => ['underline' => true]]);

        $path = app(TemplatePackage::class)->export($version->fresh());

        $this->assertFileExists($path);

        $imported = app(TemplatePackage::class)->import($path);

        $this->assertSame(DocumentTemplateVersion::STATUS_DRAFT, $imported->status, 'สิ่งที่นำเข้าต้องเป็นฉบับร่างเสมอ');
        $this->assertNotSame($version->document_template_id, $imported->document_template_id, 'ต้องไม่ทับแม่แบบเดิม');

        $mapping = $imported->fieldMappings()->firstOrFail();
        $this->assertSame(111.0, (float) $mapping->x);
        $this->assertSame('thai_date', $mapping->format);
        $this->assertSame(['underline' => true], $mapping->options);
        $this->assertTrue(Storage::disk('private_uploads')->exists($imported->pdf_file_path));

        @unlink($path);
    }

    public function test_an_admin_can_download_and_upload_the_package_from_the_screen(): void
    {
        $version = $this->draft('screen_package_form');
        $this->map($version);

        $download = $this->actingAs($this->admin())
            ->get("/api/admin/document-engine/versions/{$version->id}/package")
            ->assertOk();

        $zip = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        file_put_contents($zip, $download->streamedContent());

        $this->actingAs($this->admin())
            ->post('/api/admin/document-engine/templates/import', [
                'file' => new UploadedFile($zip, 'form.zip', 'application/zip', null, true),
            ])
            ->assertCreated()
            ->assertJsonStructure(['message', 'template_id', 'version']);

        @unlink($zip);
    }

    public function test_a_package_that_is_not_from_this_system_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'ไม่ใช่แพ็กเกจแบบฟอร์ม');
        $zip->close();

        $this->actingAs($this->admin())
            ->post('/api/admin/document-engine/templates/import', [
                'file' => new UploadedFile($path, 'x.zip', 'application/zip', null, true),
            ])
            ->assertStatus(422);

        @unlink($path);
    }

    public function test_package_endpoints_require_the_document_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $version = $this->draft('guarded_package_form');

        $this->actingAs($outsider)->get("/api/admin/document-engine/versions/{$version->id}/package")->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/admin/document-engine/versions/{$version->id}/preflight")->assertForbidden();
        $this->actingAs($outsider)->get("/api/admin/document-engine/versions/{$version->id}/calibration-sheet")->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/admin/document-engine/calibrations/solve', [
            'measured_a_x_mm' => 1, 'measured_a_y_mm' => 1, 'measured_ab_mm' => 1, 'measured_ac_mm' => 1,
        ])->assertForbidden();
    }
}
