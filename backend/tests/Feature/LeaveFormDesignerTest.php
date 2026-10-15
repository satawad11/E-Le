<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DefaultDocumentTemplateService;
use App\Services\DocumentEngine\DocumentEngine;
use App\Services\DocumentEngine\LeaveDocumentGateway;
use App\Services\DocumentEngine\LeaveFormFieldMap;
use App\Services\DocumentEngine\LeaveFormGenerator;
use App\Services\DocumentEngine\LeaveTypeForms;
use App\Services\DocumentEngine\PdfOverlayRenderer;
use App\Services\DocumentEngine\Providers\LeaveRequestDataProvider;
use App\Support\FormDate;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ออกแบบแบบฟอร์มใบลารายประเภทการลาด้วย Visual Designer ของ Document Engine
 *
 * - สร้างแบบฟอร์มเปล่า + วางฟิลด์ครบทุกช่องอัตโนมัติจากแบบฟอร์มในระบบ
 * - เปิด/ปิดการใช้งานรายประเภทการลาจากหน้าผู้ดูแล (ต้องเผยแพร่ก่อน)
 * - ใบลาที่ยื่นหลังเปิดใช้งาน pin เวอร์ชัน PDF และพิมพ์ด้วยแบบฟอร์มที่ออกแบบ
 * - สวิตช์ฉุกเฉิน DOCUMENT_ENGINE_ENABLED=false พากลับเส้นทางเดิมทันที
 */
class LeaveFormDesignerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        ApprovalStep::query()->delete();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function vacationType(): LeaveType
    {
        return LeaveType::create(['name' => 'ลาพักผ่อนออกแบบ', 'code_prefix' => 'ลพ.', 'document_template_code' => 'leave_vacation']);
    }

    /** แบบฟอร์มที่ออกแบบแล้วและเผยแพร่ (ไม่ผ่านตัวสร้าง เพื่อให้เทสต์เร็ว) */
    private function publishedDesignerForm(string $code = 'leave_vacation'): DocumentTemplateVersion
    {
        $template = DocumentTemplate::withoutEvents(fn () => DocumentTemplate::create([
            'code' => LeaveFormGenerator::TEMPLATE_PREFIX.$code, 'name' => 'แบบฟอร์มออกแบบ', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => '', 'is_active' => true,
        ]));

        $path = 'document-templates/'.$template->code.'-v1.pdf';
        Storage::disk('private_uploads')->put($path, (string) file_get_contents(resource_path('document-templates/leave_vacation.pdf')));

        $version = $template->versions()->create([
            'version' => 1, 'status' => DocumentTemplateVersion::STATUS_ACTIVE, 'render_mode' => DocumentTemplate::RENDER_PDF_OVERLAY,
            'pdf_file_path' => $path, 'page_count' => 1, 'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
            'content_html' => '', 'effective_from' => now()->toDateString(), 'is_active' => true,
        ]);

        foreach ([
            ['employee.full_name', 'TEXT', 100, 200, 200, 20],
            ['approval.authorizer.approved', 'CHECKBOX', 300, 500, 12, 12],
            ['leave.is_sick', 'CHECKBOX', 100, 300, 12, 12],
        ] as [$field, $type, $x, $y, $w, $h]) {
            $version->fieldMappings()->create([
                'field_code' => $field, 'field_type' => $type, 'page' => 1, 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
                'font_size' => 14, 'align' => 'left', 'overflow' => 'shrink', 'options' => ['mark' => '/'], 'is_active' => true,
            ]);
        }

        return $version;
    }

    private function leave(LeaveType $type, array $attributes = []): Leave
    {
        $user = User::factory()->create(['department_id' => Department::create(['name' => 'แผนกทดสอบ'])->id]);

        return Leave::create(array_merge([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-11-16', 'end_date' => '2026-11-17', 'total_days' => 2,
            'reason' => 'พักผ่อน', 'status' => 'Approved',
        ], $attributes));
    }

    // ─────────────────────────── สร้างแบบฟอร์มอัตโนมัติ ───────────────────────────

    public function test_generator_builds_a_blank_form_with_every_field_placed(): void
    {
        $this->artisan('document:install-fonts')->assertSuccessful();

        $version = app(LeaveFormGenerator::class)->generate('leave_vacation');

        $this->assertSame('designer_leave_vacation', $version->template->code);
        $this->assertSame(DocumentTemplateVersion::STATUS_DRAFT, $version->status);
        $this->assertSame(1, $version->template->versions()->count(), 'ต้องไม่มีเวอร์ชัน HTML เปล่าที่ model event สร้างให้');
        $this->assertStringStartsWith('%PDF', Storage::disk('private_uploads')->get($version->pdf_file_path));

        $mappings = $version->fieldMappings()->get()->groupBy('field_code');

        foreach (['employee.full_name', 'employee.position', 'leave.start_date', 'leave.end_date', 'leave.total_days',
            'vacation.accumulated', 'approval.authorizer.name', 'approval.commander.date', 'leave.document_year'] as $code) {
            $this->assertTrue($mappings->has($code), "ต้องวางฟิลด์ {$code}");
        }
        $this->assertSame('CHECKBOX', $mappings['approval.authorizer.approved']->first()->field_type);
        $this->assertSame('SIGNATURE', $mappings['leave.signature']->first()->field_type);
        $this->assertCount(2, $mappings['employee.full_name'], 'ชื่อผู้ลาอยู่ทั้งบรรทัด "ข้าพเจ้า" และใต้ลายเซ็น');

        // ทุกฟิลด์ต้องอยู่ในหน้ากระดาษ A4
        foreach ($version->fieldMappings as $mapping) {
            $this->assertTrue($mapping->x >= 0 && $mapping->x + $mapping->width <= 596 && $mapping->y >= 0 && $mapping->y + $mapping->height <= 842, $mapping->field_code);
        }

        // พรีวิวบนหน้าออกแบบใช้ค่าตัวอย่างจริงและเรนเดอร์ช่องติ๊กได้
        $this->assertStringStartsWith('%PDF', app(DocumentEngine::class)->preview($version->fresh()));

        /*
         * บันทึกไปราชการ: ช่อง "มอบหมายผู้ปฏิบัติงานแทน" เคยติดพื้นหลังเป็นช่องที่ติ๊กแล้ว
         * (ตัวสร้างจะโยน LogicException ถ้าพื้นหลังมีช่องที่ติ๊กอยู่)
         */
        $travel = app(LeaveFormGenerator::class)->generate('official_travel');
        $codes = $travel->fieldMappings()->pluck('field_code');
        $this->assertContains('leave.has_substitute', $codes);
        $this->assertContains('extra.expense_type.เบิกตามสิทธิ', $codes);
        $this->assertContains('extra.companion_6', $codes);
    }

    public function test_checked_checkbox_fields_render_without_crashing(): void
    {
        $this->artisan('document:install-fonts')->assertSuccessful();
        $version = $this->publishedDesignerForm();
        $type = LeaveType::create(['name' => 'ลาป่วย', 'document_template_code' => 'leave_sick_personal']);
        $leave = $this->leave($type);
        LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1, 'approver_name' => 'ผู้อำนวยการ', 'status' => 'Approved', 'approved_at' => now()]);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1, 'name' => 'ผู้อำนวยการ']);

        // เดิมเครื่องหมายใช้ฟอนต์ Helvetica ที่ไม่มีในโฟลเดอร์ฟอนต์ของ engine — ติ๊กช่องใดก็ล้ม
        $content = app(PdfOverlayRenderer::class)->render($version, [
            'employee.full_name' => 'นายทดสอบ', 'approval.authorizer.approved' => true, 'leave.is_sick' => true,
        ]);

        $this->assertStringStartsWith('%PDF', $content);
    }

    // ─────────────────────────── ค่าของฟิลด์ ───────────────────────────

    public function test_field_values_follow_the_html_form_data(): void
    {
        $type = $this->vacationType();
        $type->update(['extra_fields' => [['key' => 'trip_mode', 'label' => 'การเดินทาง', 'type' => 'select', 'options' => ['รถยนต์', 'เครื่องบิน']]]]);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1, 'name' => 'หัวหน้างานบุคลากร']);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 2, 'name' => 'รองผู้อำนวยการ']);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 3, 'name' => 'ผู้อำนวยการ']);

        $leave = $this->leave($type, ['meta_data' => ['extra_fields' => ['trip_mode' => 'เครื่องบิน']]]);
        foreach ([1 => 'นางบุคลากร', 2 => 'นายรอง', 3 => 'นายผู้อำนวยการ'] as $order => $name) {
            LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => $order, 'approver_name' => $name, 'status' => 'Approved', 'approved_at' => '2026-11-10 09:00:00']);
        }

        $values = app(LeaveRequestDataProvider::class)->fields(['leave' => $leave->fresh()]);

        $this->assertSame('นางบุคลากร', $values['approval.checker.name']);
        $this->assertSame('นายรอง', $values['approval.commander.name']);
        $this->assertSame('นายผู้อำนวยการ', $values['approval.authorizer.name']);
        $this->assertSame('10/11/2569', $values['approval.authorizer.date']);
        $this->assertTrue($values['approval.authorizer.approved']);
        $this->assertFalse($values['approval.authorizer.rejected']);
        $this->assertSame(2570, $values['leave.fiscal_year'], 'ใบลา พ.ย. 2569 อยู่ในปีงบประมาณ 2570');
        $this->assertSame('-', $values['last_leave.start_date'], 'ไม่มีใบลาครั้งก่อนต้องพิมพ์ -');
        $this->assertSame('เครื่องบิน', $values['extra.trip_mode']);
        $this->assertTrue($values['extra.trip_mode.เครื่องบิน']);
        $this->assertFalse($values['extra.trip_mode.รถยนต์']);
    }

    public function test_signer_roles_on_the_form(): void
    {
        $block = fn (string $role) => ['role_name' => $role];

        $this->assertSame(['checker' => 0, 'commander' => 1, 'authorizer' => 2], LeaveFormFieldMap::roles([$block('HR Head'), $block('Deputy Director'), $block('Director')]));
        $this->assertSame(['checker' => null, 'commander' => 0, 'authorizer' => 1], LeaveFormFieldMap::roles([$block('Deputy Director'), $block('Director')]));
        $this->assertSame(['checker' => null, 'commander' => 0, 'authorizer' => 0], LeaveFormFieldMap::roles([$block('Director')]));
        $this->assertSame(['checker' => null, 'commander' => null, 'authorizer' => null], LeaveFormFieldMap::roles([]));
    }

    public function test_form_date_prints_buddhist_era(): void
    {
        $this->assertSame('10/11/2569', FormDate::short(Carbon::parse('2026-11-10')));
        $this->assertSame('', FormDate::short(null));
    }

    // ─────────────────────────── สวิตช์รายประเภทการลา ───────────────────────────

    public function test_a_form_must_be_published_before_it_can_be_enabled(): void
    {
        $type = $this->vacationType();
        $forms = app(LeaveTypeForms::class);

        try {
            $forms->setActive((string) $type->id, true);
            $this->fail('ยังไม่มีแบบฟอร์มต้องเปิดไม่ได้');
        } catch (\DomainException) {
        }

        $draft = $this->publishedDesignerForm();
        $draft->update(['status' => DocumentTemplateVersion::STATUS_DRAFT]);

        $this->expectException(\DomainException::class);
        $forms->setActive((string) $type->id, true);
    }

    public function test_enabled_form_is_pinned_and_used_for_new_leaves_only(): void
    {
        $this->artisan('document:install-fonts')->assertSuccessful();
        // เหมือน production: แบบฟอร์ม HTML ตั้งต้นลงทะเบียนไว้ ใบลาเดิมจึง pin เวอร์ชัน HTML
        app(DefaultDocumentTemplateService::class)->sync();
        $type = $this->vacationType();
        $before = $this->leave($type);
        $version = $this->publishedDesignerForm();

        app(LeaveTypeForms::class)->setActive((string) $type->id, true);
        $after = $this->leave($type);

        $this->assertSame($version->id, $after->document_template_version_id, 'ใบลาใหม่ต้อง pin แบบฟอร์มที่ออกแบบ');
        $this->assertNotSame($version->id, $before->document_template_version_id, 'ใบลาเดิมต้องคงแบบฟอร์มเดิม');

        $gateway = app(LeaveDocumentGateway::class);
        $this->assertTrue($gateway->handles($after));
        $this->assertStringStartsWith('%PDF', (string) $gateway->pdfFor($after->fresh()));
        $this->assertNull($gateway->pdfFor($before->fresh()), 'ใบลาเดิมพิมพ์ด้วยเส้นทางเดิม');

        app(LeaveTypeForms::class)->setActive((string) $type->id, false);
        $this->assertNotSame($version->id, $this->leave($type)->document_template_version_id);
    }

    public function test_kill_switch_returns_every_leave_to_the_old_path(): void
    {
        $type = $this->vacationType();
        $this->publishedDesignerForm();
        app(LeaveTypeForms::class)->setActive((string) $type->id, true);
        config(['document_engine.enabled' => false]);

        $leave = $this->leave($type);

        $this->assertFalse(app(LeaveDocumentGateway::class)->handles($leave));
        $this->assertNull(app(LeaveDocumentGateway::class)->pdfFor($leave));
    }

    // ─────────────────────────── หน้าผู้ดูแล ───────────────────────────

    public function test_admin_page_lists_each_leave_type_and_toggles_its_form(): void
    {
        $admin = $this->admin();
        $type = $this->vacationType();

        $rows = collect($this->actingAs($admin)->getJson('/api/admin/document-engine/leave-type-forms')->assertOk()->json())->keyBy('target');
        $this->assertTrue($rows->has((string) $type->id));
        $this->assertTrue($rows->has('cancel'));
        $this->assertSame('leave_vacation', $rows[(string) $type->id]['form_code']);
        $this->assertFalse($rows[(string) $type->id]['enabled']);

        $this->actingAs($admin)->putJson("/api/admin/document-engine/leave-type-forms/{$type->id}", ['enabled' => true])->assertStatus(422);

        $this->publishedDesignerForm();
        $this->actingAs($admin)->putJson("/api/admin/document-engine/leave-type-forms/{$type->id}", ['enabled' => true])->assertOk();

        $row = collect($this->actingAs($admin)->getJson('/api/admin/document-engine/leave-type-forms')->json())->firstWhere('target', (string) $type->id);
        $this->assertTrue($row['enabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_form_designer_enabled']);
    }

    public function test_staff_cannot_manage_leave_type_forms(): void
    {
        $user = User::factory()->create();
        $type = $this->vacationType();

        $this->actingAs($user)->getJson('/api/admin/document-engine/leave-type-forms')->assertForbidden();
        $this->actingAs($user)->putJson("/api/admin/document-engine/leave-type-forms/{$type->id}", ['enabled' => true])->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/document-engine/leave-type-forms/{$type->id}/generate")->assertForbidden();
    }

    public function test_field_dictionary_offers_each_leave_types_own_fields(): void
    {
        $type = $this->vacationType();
        $type->update(['extra_fields' => [['key' => 'vehicle_plate', 'label' => 'ทะเบียนรถ', 'type' => 'text']]]);

        $codes = collect($this->actingAs($this->admin())->getJson('/api/admin/document-engine/fields')->assertOk()->json())->pluck('code');

        $this->assertContains('extra.vehicle_plate', $codes);
        $this->assertContains('approval.commander.signature', $codes);
        $this->assertContains('stats.sick.before', $codes);
        $this->assertNotContains('school_name', $codes, 'ตัวแปรแม่แบบ HTML เดิมไม่ต้องแสดงในหน้าออกแบบแล้ว');
    }
}
