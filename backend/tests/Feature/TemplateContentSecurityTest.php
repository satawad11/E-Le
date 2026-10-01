<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DocumentEngine\TemplateSyntaxException;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DC-02 — สิทธิ์จัดการแม่แบบต้องแก้หน้าตาเอกสารได้ แต่รันโค้ดบนเซิร์ฟเวอร์ไม่ได้
 *
 * ทุกเคสใช้ payload ที่ "ถ้าถูกรัน" จะเขียนไฟล์ลงดิสก์ แล้วตรวจว่าไฟล์ไม่เกิด
 * ไม่ใช้คำสั่งที่อ่านความลับหรือแตะระบบจริง
 */
class TemplateContentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = storage_path('framework/testing/dc02-'.uniqid().'.txt');
        @mkdir(dirname($this->marker), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);

        parent::tearDown();
    }

    private function payload(): string
    {
        return "<p>หัวกระดาษ</p>@php file_put_contents('{$this->marker}', 'ran'); @endphp";
    }

    private function templateManager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'document.template.manage', 'guard_name' => 'web']));

        return $user;
    }

    private function htmlTemplate(string $code = 'leave_vacation', string $content = '<p>{{ $user_name }}</p>'): DocumentTemplate
    {
        return DocumentTemplate::create([
            'code' => $code, 'name' => 'แบบทดสอบ', 'module' => 'leave', 'document_type' => 'FORM',
            'paper_size' => 'A4', 'orientation' => 'portrait', 'content_html' => $content, 'is_active' => true,
        ]);
    }

    public function test_saving_code_into_a_template_is_rejected_and_nothing_changes(): void
    {
        $template = $this->htmlTemplate();

        $this->actingAs($this->templateManager())
            ->putJson("/api/admin/document-engine/templates/{$template->id}/content", [
                'name' => 'แบบทดสอบ',
                'content_html' => $this->payload(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_html');

        $this->assertSame('<p>{{ $user_name }}</p>', $template->fresh()->content_html);
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_safe_edits_are_saved(): void
    {
        $template = $this->htmlTemplate();

        $this->actingAs($this->templateManager())
            ->putJson("/api/admin/document-engine/templates/{$template->id}/content", [
                'name' => 'แบบทดสอบ',
                'content_html' => "<p>เรียน {{ \$user_name ?? '' }}</p>@if(\$reason)<p>{{ \$reason }}</p>@endif",
            ])
            ->assertOk();
    }

    public function test_preview_does_not_execute_expressions(): void
    {
        $this->actingAs($this->templateManager())
            ->postJson('/api/admin/document-engine/templates/preview', [
                'content_html' => "{{ file_put_contents('{$this->marker}', 'ran') }}",
            ])
            ->assertUnprocessable();

        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_preview_of_a_safe_template_returns_a_pdf(): void
    {
        $this->actingAs($this->templateManager())
            ->post('/api/admin/document-engine/templates/preview', ['content_html' => '<p>{{ $user_name }}</p>'])
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_exact_copy_of_a_project_form_is_trusted(): void
    {
        $original = (string) file_get_contents(resource_path('views/pdf/leave_vacation.blade.php'));

        $this->assertSame('pdf.leave_vacation', app(LeaveDocumentTemplateResolver::class)->trustedViewFor($original));
        $this->assertSame('pdf.leave_vacation', app(LeaveDocumentTemplateResolver::class)->trustedViewFor(str_replace("\n", "\r\n", $original)));
        $this->assertNull(app(LeaveDocumentTemplateResolver::class)->trustedViewFor($original.$this->payload()));
    }

    public function test_legacy_unsafe_content_already_in_the_database_is_never_executed_when_printing(): void
    {
        $this->htmlTemplate('leave_sick_personal', $this->payload());

        $department = OrgUnit::factory()->create();
        $owner = User::factory()->create(['org_unit_id' => $department->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'S']);
        $leave = Leave::create([
            'user_id' => $owner->id, 'leave_type_id' => $type->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => 'Approved', 'approval_level' => 1,
        ])->fresh();

        $html = app(LeaveDocumentTemplateResolver::class)->render($leave, 'pdf.leave', []);

        $this->assertFileDoesNotExist($this->marker);
        $this->assertStringNotContainsString('หัวกระดาษ', $html, 'เนื้อหาที่ไม่ผ่านไวยากรณ์ต้องถูกแทนด้วยแบบฟอร์มตั้งต้นทั้งฉบับ');
    }

    public function test_unsafe_content_without_a_fallback_form_throws(): void
    {
        $this->expectException(TemplateSyntaxException::class);

        try {
            app(LeaveDocumentTemplateResolver::class)->renderContent($this->payload(), []);
        } finally {
            $this->assertFileDoesNotExist($this->marker);
        }
    }
}
