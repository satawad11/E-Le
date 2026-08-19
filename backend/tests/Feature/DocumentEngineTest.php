<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Document\DocumentBindingRule;
use App\Models\Document\DocumentGeneratedFile;
use App\Models\Document\DocumentPrintCalibration;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DocumentEngine\DocumentEngine;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\DocumentEngine\Providers\LeaveRequestDataProvider;
use App\Services\DocumentEngine\TemplateResolver;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Document & Report Engine — ครอบคลุมตั้งแต่การผูกแบบฟอร์ม การเลือกเวอร์ชัน
 * การวางฟิลด์ ภาษาไทย เอกสารฉบับสมบูรณ์ที่แก้ไม่ได้ และการถอยไปเส้นทางเดิม
 */
class DocumentEngineTest extends TestCase
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

    /** แบบฟอร์ม PDF ต้นฉบับที่โครงการใช้จริง */
    private function sourceForm(string $file = 'leave_vacation.pdf'): string
    {
        $path = resource_path('document-templates/'.$file);

        if (! is_file($path)) {
            $this->markTestSkipped('ไม่พบแบบฟอร์มต้นฉบับ '.$file);
        }

        return $path;
    }

    /** สร้างแม่แบบชนิด PDF พร้อมเวอร์ชันที่มีไฟล์ต้นฉบับแล้ว */
    private function pdfTemplate(string $code = 'test_form', string $status = DocumentTemplateVersion::STATUS_ACTIVE): DocumentTemplateVersion
    {
        $template = DocumentTemplate::create([
            'code' => $code, 'name' => 'แบบฟอร์มทดสอบ', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]);

        $stored = 'document-templates/'.$code.'.pdf';
        Storage::disk('private_uploads')->put($stored, (string) file_get_contents($this->sourceForm()));

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

    /** @return array{0: Leave, 1: LeaveType, 2: User} */
    private function approvedLeave(): array
    {
        $dept = Department::create(['name' => 'แผนกทดสอบเอกสาร']);
        $user = User::factory()->create(['department_id' => $dept->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาพักผ่อน', 'code_prefix' => 'V']);

        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDay()->toDateString(),
            'total_days' => 2, 'reason' => 'พักผ่อนประจำปี',
            'status' => 'Approved', 'approval_level' => 1, 'document_number' => 'V-0001/2569',
        ]);

        LeaveApproval::create([
            'leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1,
            'step_name' => 'ผู้อำนวยการ', 'status' => 'Approved',
            'approver_name' => 'นายผู้อำนวยการ ทดสอบ', 'approver_position' => 'ผู้อำนวยการ',
            'approved_at' => now(),
        ]);

        return [$leave->fresh(), $type, $user];
    }

    private function bind(DocumentTemplate $template, int $leaveTypeId): void
    {
        DocumentBindingRule::create([
            'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => $leaveTypeId,
            'document_template_id' => $template->id, 'priority' => 10, 'is_active' => true,
        ]);
    }

    private function map(DocumentTemplateVersion $version, array $overrides = []): void
    {
        $version->fieldMappings()->create(array_merge([
            'field_code' => 'employee.full_name', 'field_type' => 'TEXT', 'page' => 1,
            'x' => 120, 'y' => 200, 'width' => 260, 'height' => 20, 'font_size' => 14,
            'align' => 'left', 'overflow' => 'shrink', 'is_active' => true,
        ], $overrides));
    }

    // ───────────────────────── Template & Version Resolver ─────────────────────────

    public function test_binding_rule_picks_the_template_for_a_leave_type(): void
    {
        $version = $this->pdfTemplate();
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $resolved = app(TemplateResolver::class)->resolveTemplate('LEAVE', 'LEAVE_TYPE', $type->id);

        $this->assertSame($version->document_template_id, $resolved?->id);
    }

    public function test_a_rule_for_the_specific_entity_wins_over_the_catch_all_rule(): void
    {
        $specific = $this->pdfTemplate('specific_form');
        $catchAll = $this->pdfTemplate('catch_all_form');
        [$leave, $type] = $this->approvedLeave();

        DocumentBindingRule::create([
            'module' => 'LEAVE', 'entity_type' => 'LEAVE_TYPE', 'entity_id' => null,
            'document_template_id' => $catchAll->document_template_id, 'priority' => 1, 'is_active' => true,
        ]);
        $this->bind($specific->template, $type->id);

        $resolved = app(TemplateResolver::class)->resolveTemplate('LEAVE', 'LEAVE_TYPE', $type->id);

        $this->assertSame($specific->document_template_id, $resolved?->id);
    }

    public function test_several_leave_types_can_share_one_template(): void
    {
        $version = $this->pdfTemplate('shared_form');
        $sick = LeaveType::create(['name' => 'ลาป่วย']);
        $personal = LeaveType::create(['name' => 'ลากิจส่วนตัว']);

        $this->bind($version->template, $sick->id);
        $this->bind($version->template, $personal->id);

        $resolver = app(TemplateResolver::class);

        $this->assertSame($version->document_template_id, $resolver->resolveTemplate('LEAVE', 'LEAVE_TYPE', $sick->id)?->id);
        $this->assertSame($version->document_template_id, $resolver->resolveTemplate('LEAVE', 'LEAVE_TYPE', $personal->id)?->id);
    }

    public function test_a_retired_version_is_not_used_for_new_documents(): void
    {
        $version = $this->pdfTemplate('retired_form');
        $version->update(['status' => DocumentTemplateVersion::STATUS_RETIRED, 'is_active' => false]);

        $this->assertNull(app(TemplateResolver::class)->activeVersion($version->template));
    }

    public function test_a_document_keeps_the_version_it_was_pinned_to(): void
    {
        $old = $this->pdfTemplate('pinned_form');
        $template = $old->template;

        $new = $template->versions()->create([
            'version' => 2, 'status' => DocumentTemplateVersion::STATUS_ACTIVE,
            'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY, 'pdf_file_path' => $old->pdf_file_path,
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
            'content_html' => '', 'effective_from' => now()->toDateString(), 'is_active' => true,
        ]);
        $old->update(['status' => DocumentTemplateVersion::STATUS_RETIRED, 'is_active' => false]);

        $resolver = app(TemplateResolver::class);

        $this->assertSame($new->id, $resolver->versionForDocument(null, $template)?->id);
        $this->assertSame($old->id, $resolver->versionForDocument($old->id, $template)?->id);
    }

    public function test_a_version_with_documents_attached_cannot_be_deleted(): void
    {
        $version = $this->pdfTemplate('locked_form');
        [$leave] = $this->approvedLeave();
        $leave->update(['document_template_version_id' => $version->id]);

        $this->expectException(\RuntimeException::class);
        $version->delete();
    }

    // ───────────────────────────── Rendering ─────────────────────────────

    public function test_it_renders_thai_text_onto_the_official_form(): void
    {
        $version = $this->pdfTemplate('thai_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $result = app(DocumentEngine::class)->generate(
            module: 'LEAVE', entityType: 'LEAVE_TYPE', entityId: $type->id,
            dataSource: LeaveRequestDataProvider::CODE, context: ['leave' => $leave],
        );

        $this->assertStringStartsWith('%PDF', $result->content);
        $this->assertGreaterThan(10000, strlen($result->content));
        // ค่าที่ส่งให้แบบฟอร์มต้องเป็นภาษาไทยของจริง ไม่ใช่ค่าว่าง
        $this->assertNotSame('', trim((string) $result->values['employee.full_name']));
    }

    public function test_checkbox_and_signature_fields_render_without_failing(): void
    {
        $version = $this->pdfTemplate('checkbox_form');
        $this->map($version, ['field_code' => 'leave.is_sick', 'field_type' => 'CHECKBOX', 'x' => 100, 'y' => 300, 'width' => 12, 'height' => 12]);
        $this->map($version, ['field_code' => 'approval.authorizer.signature', 'field_type' => 'SIGNATURE', 'x' => 320, 'y' => 600, 'width' => 90, 'height' => 40]);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $result = app(DocumentEngine::class)->generate(
            module: 'LEAVE', entityType: 'LEAVE_TYPE', entityId: $type->id,
            dataSource: LeaveRequestDataProvider::CODE, context: ['leave' => $leave],
        );

        $this->assertStringStartsWith('%PDF', $result->content);
    }

    public function test_print_calibration_applies_without_touching_the_mapping(): void
    {
        $version = $this->pdfTemplate('calibrated_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        DocumentPrintCalibration::create(['scope' => 'GLOBAL', 'x_offset' => 5, 'y_offset' => -3, 'scale' => 1, 'is_active' => true]);
        DocumentPrintCalibration::create([
            'scope' => 'TEMPLATE', 'document_template_id' => $version->document_template_id,
            'x_offset' => 12, 'y_offset' => 8, 'scale' => 1, 'is_active' => true,
        ]);

        $calibration = app(DocumentEngine::class)->calibrationFor($version->template, null);

        // ค่าที่เจาะจงกว่า (ระดับแม่แบบ) ต้องอยู่ทับค่า global
        $this->assertSame(12.0, $calibration['x_offset']);
        $this->assertSame(8.0, $calibration['y_offset']);
        // ตำแหน่งฟิลด์ที่บันทึกไว้ต้องไม่ถูกแก้เพราะการชดเชยเครื่องพิมพ์
        $this->assertSame(120.0, (float) $version->fieldMappings()->first()->x);
    }

    // ─────────────────────── Final document / Immutability ───────────────────────

    public function test_a_finalized_document_cannot_be_modified_or_deleted(): void
    {
        $version = $this->pdfTemplate('final_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $engine = app(DocumentEngine::class);
        $result = $engine->generate('LEAVE', 'LEAVE_TYPE', $type->id, LeaveRequestDataProvider::CODE, ['leave' => $leave]);
        $file = $engine->store($result, 'LEAVE', 'Leave', (string) $leave->id, finalize: true);

        $this->assertSame(DocumentGeneratedFile::STATUS_FINALIZED, $file->status);
        $this->assertSame(hash('sha256', $result->content), $file->sha256);

        $this->expectException(\RuntimeException::class);
        $file->update(['file_path' => 'documents/hacked.pdf']);
    }

    public function test_regenerating_creates_a_new_revision_and_keeps_the_old_one(): void
    {
        $version = $this->pdfTemplate('revision_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $engine = app(DocumentEngine::class);
        $first = $engine->store(
            $engine->generate('LEAVE', 'LEAVE_TYPE', $type->id, LeaveRequestDataProvider::CODE, ['leave' => $leave]),
            'LEAVE', 'Leave', (string) $leave->id, finalize: true,
        );
        $second = $engine->store(
            $engine->generate('LEAVE', 'LEAVE_TYPE', $type->id, LeaveRequestDataProvider::CODE, ['leave' => $leave]),
            'LEAVE', 'Leave', (string) $leave->id, finalize: true,
        );

        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $second->revision);
        $this->assertSame(DocumentGeneratedFile::STATUS_SUPERSEDED, $first->fresh()->status);
        // ไฟล์ฉบับเดิมต้องยังอยู่ ไม่ถูกเขียนทับ
        $this->assertTrue(Storage::disk('private_uploads')->exists($first->file_path));
        $this->assertNotSame($first->file_path, $second->file_path);
    }

    public function test_a_historical_document_is_served_from_the_stored_file(): void
    {
        config(['document_engine.enabled' => true, 'document_engine.entities.leave_types' => ['*']]);

        $version = $this->pdfTemplate('historical_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $gateway = app(LeaveDocumentGateway::class);
        $first = $gateway->pdfFor($leave);
        $stored = app(DocumentEngine::class)->latestFor('Leave', (string) $leave->id);

        // เปลี่ยนตำแหน่งฟิลด์ทีหลัง — เอกสารเดิมต้องไม่เปลี่ยนตาม
        $version->fieldMappings()->update(['x' => 400, 'y' => 700]);

        $second = $gateway->pdfFor($leave->fresh());

        $this->assertNotNull($stored);
        $this->assertSame($first, $second);
        $this->assertSame(1, DocumentGeneratedFile::where('source_id', (string) $leave->id)->count());
    }

    // ─────────────────────────── Legacy compatibility ───────────────────────────

    public function test_the_engine_is_off_by_default_and_leaves_the_old_path_untouched(): void
    {
        [$leave] = $this->approvedLeave();

        $this->assertFalse(config('document_engine.enabled'));
        $this->assertFalse(app(LeaveDocumentGateway::class)->handles($leave));
        $this->assertNull(app(LeaveDocumentGateway::class)->pdfFor($leave));
    }

    public function test_only_the_leave_types_in_the_flag_use_the_engine(): void
    {
        [$leave, $type] = $this->approvedLeave();
        $other = LeaveType::create(['name' => 'ลาป่วยทดสอบธง']);
        $otherLeave = Leave::create([
            'user_id' => $leave->user_id, 'leave_type_id' => $other->id,
            'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบธง', 'status' => 'Approved', 'approval_level' => 1,
        ]);

        config(['document_engine.enabled' => true, 'document_engine.entities.leave_types' => [(string) $type->id]]);

        $gateway = app(LeaveDocumentGateway::class);
        $this->assertTrue($gateway->handles($leave));
        $this->assertFalse($gateway->handles($otherLeave));
    }

    public function test_an_unbound_leave_type_falls_back_to_the_existing_renderer(): void
    {
        config(['document_engine.enabled' => true, 'document_engine.entities.leave_types' => ['*']]);
        [$leave] = $this->approvedLeave();

        // ไม่มี binding rule → engine ต้องคืน null เพื่อให้เส้นทางเดิมทำงาน ไม่ใช่โยน error ใส่ผู้ใช้
        $this->assertNull(app(LeaveDocumentGateway::class)->pdfFor($leave));
    }

    public function test_the_leave_pdf_endpoint_still_works_with_the_engine_enabled(): void
    {
        config(['document_engine.enabled' => true, 'document_engine.entities.leave_types' => ['*']]);

        $version = $this->pdfTemplate('endpoint_form');
        $this->map($version);
        [$leave, $type, $owner] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $response = $this->actingAs($owner)->get("/api/leaves/{$leave->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    // ───────────────────────────── Authorization / IDOR ─────────────────────────────

    public function test_admin_endpoints_reject_users_without_document_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $version = $this->pdfTemplate('guarded_form');

        $this->actingAs($outsider)->getJson('/api/admin/document-engine/overview')->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/admin/document-engine/templates')->assertForbidden();
        $this->actingAs($outsider)->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", ['mappings' => []])->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/admin/document-engine/versions/{$version->id}/pdf")->assertForbidden();
    }

    public function test_another_persons_leave_document_cannot_be_downloaded(): void
    {
        config(['document_engine.enabled' => true, 'document_engine.entities.leave_types' => ['*']]);

        $version = $this->pdfTemplate('idor_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)->get("/api/leaves/{$leave->id}/pdf")->assertForbidden();
    }

    // ───────────────────────────── Admin flow ─────────────────────────────

    public function test_an_admin_can_upload_map_publish_and_preview_a_form(): void
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin)->postJson('/api/admin/document-engine/templates', [
            'code' => 'admin_flow_form', 'name' => 'แบบฟอร์มจากหน้าผู้ดูแล', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => 'pdf_overlay',
        ])->assertCreated();

        $templateId = $create->json('template.id');

        $version = $this->actingAs($admin)
            ->postJson("/api/admin/document-engine/templates/{$templateId}/versions")
            ->assertCreated()->json('version');

        $this->actingAs($admin)->post("/api/admin/document-engine/versions/{$version['id']}/pdf", [
            'file' => new UploadedFile($this->sourceForm(), 'form.pdf', 'application/pdf', null, true),
        ])->assertOk();

        $this->actingAs($admin)->putJson("/api/admin/document-engine/versions/{$version['id']}/mappings", [
            'mappings' => [[
                'field_code' => 'employee.full_name', 'field_type' => 'TEXT', 'page' => 1,
                'x' => 130, 'y' => 210, 'width' => 250, 'height' => 20, 'font_size' => 14, 'align' => 'left',
            ]],
        ])->assertOk()->assertJsonCount(1, 'mappings');

        $this->actingAs($admin)->get("/api/admin/document-engine/versions/{$version['id']}/preview")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)->postJson("/api/admin/document-engine/versions/{$version['id']}/publish")->assertOk();

        $this->assertSame(
            DocumentTemplateVersion::STATUS_ACTIVE,
            DocumentTemplateVersion::find($version['id'])->status,
        );
    }

    public function test_a_published_version_cannot_have_its_fields_moved(): void
    {
        $admin = $this->admin();
        $version = $this->pdfTemplate('published_form');

        $this->actingAs($admin)->putJson("/api/admin/document-engine/versions/{$version->id}/mappings", [
            'mappings' => [[
                'field_code' => 'employee.full_name', 'field_type' => 'TEXT', 'page' => 1,
                'x' => 10, 'y' => 10, 'width' => 100, 'height' => 20,
            ]],
        ])->assertStatus(422);
    }

    public function test_publishing_retires_the_previous_version_without_deleting_it(): void
    {
        $admin = $this->admin();
        $first = $this->pdfTemplate('publish_flow_form');

        $second = $this->actingAs($admin)
            ->postJson("/api/admin/document-engine/templates/{$first->document_template_id}/versions")
            ->assertCreated()->json('version');

        $this->actingAs($admin)->postJson("/api/admin/document-engine/versions/{$second['id']}/publish")->assertOk();

        $this->assertSame(DocumentTemplateVersion::STATUS_RETIRED, $first->fresh()->status);
        $this->assertNotNull(DocumentTemplateVersion::find($first->id));
        $this->assertSame(DocumentTemplateVersion::STATUS_ACTIVE, DocumentTemplateVersion::find($second['id'])->status);
    }

    public function test_the_field_dictionary_and_data_sources_are_available_to_admins(): void
    {
        $admin = $this->admin();

        $fields = $this->actingAs($admin)->getJson('/api/admin/document-engine/fields')->assertOk()->json();
        $sources = $this->actingAs($admin)->getJson('/api/admin/document-engine/data-sources')->assertOk()->json();

        $this->assertNotEmpty($fields);
        $this->assertContains('employee.full_name', array_column($fields, 'code'));
        $this->assertContains(LeaveRequestDataProvider::CODE, array_column($sources, 'code'));
    }

    public function test_generating_a_document_is_recorded_in_the_audit_log(): void
    {
        $version = $this->pdfTemplate('audited_form');
        $this->map($version);
        [$leave, $type] = $this->approvedLeave();
        $this->bind($version->template, $type->id);

        $engine = app(DocumentEngine::class);
        $engine->store(
            $engine->generate('LEAVE', 'LEAVE_TYPE', $type->id, LeaveRequestDataProvider::CODE, ['leave' => $leave]),
            'LEAVE', 'Leave', (string) $leave->id, finalize: true,
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_finalized',
            'resource_type' => 'DocumentGeneratedFile',
        ]);
    }
}
