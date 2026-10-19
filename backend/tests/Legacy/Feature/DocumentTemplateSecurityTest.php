<?php

namespace Tests\Legacy\Feature;

use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DefaultDocumentTemplateService;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TPL-SEC-01 — แบบฟอร์ม HTML ต้องไม่เป็นช่องทางรันโค้ดบนเซิร์ฟเวอร์
 *
 * เดิม `content_html` ที่ผู้ดูแลแก้ได้ถูกส่งเข้า `Blade::render()` ทั้งตอนพิมพ์ใบลา
 * และตอนพรีวิว (POST /admin/document-templates/preview รับ HTML แล้วเรนเดอร์ทันที)
 * ใครมีสิทธิ์ตั้งค่าก็รัน PHP ได้ด้วย `@php ... @endphp`
 */
class DocumentTemplateSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '@php file_put_contents(storage_path("tpl-rce-marker"), "pwned"); @endphp RCE{{ 7 * 7 }}';

    protected function setUp(): void
    {
        parent::setUp();

        @unlink(storage_path('tpl-rce-marker'));
        app(DefaultDocumentTemplateService::class)->sync();
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('tpl-rce-marker'));

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    public function test_printing_a_leave_never_executes_template_html_stored_in_the_database(): void
    {
        $template = DocumentTemplate::where('code', 'leave_sick_personal')->firstOrFail();
        // บันทึกตรงลงฐานข้อมูล — จำลองค่าที่ถูกแก้มาก่อนหน้านี้ หรือถูกแก้นอกหน้าจอ
        $template->update(['content_html' => self::PAYLOAD]);

        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 60, 'document_template_code' => 'leave_sick_personal']);
        $user = User::factory()->create();
        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
            'total_days' => 1, 'reason' => 'ไม่สบาย', 'status' => 'Pending',
        ]);

        $html = app(LeaveDocumentTemplateResolver::class)->render($leave->fresh(['user', 'type']), 'pdf.leave', ['leave' => $leave]);

        $this->assertFileDoesNotExist(storage_path('tpl-rce-marker'));
        $this->assertStringNotContainsString('RCE49', $html);
        $this->assertStringContainsString('แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว', $html, 'ต้องเรนเดอร์จากไฟล์ blade ในโค้ด');
    }

    public function test_there_is_no_endpoint_that_renders_html_sent_by_the_client(): void
    {
        $admin = $this->admin();
        $template = DocumentTemplate::where('code', 'leave_vacation')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/admin/document-templates/preview', ['content_html' => self::PAYLOAD])
            ->assertStatus(405);
        $this->actingAs($admin)->postJson('/api/admin/document-templates', [
            'code' => 'evil', 'name' => 'x', 'module' => 'leave', 'content_html' => self::PAYLOAD,
        ])->assertStatus(405);
        $this->actingAs($admin)->putJson("/api/admin/document-templates/{$template->id}", [
            'name' => 'x', 'module' => 'leave', 'content_html' => self::PAYLOAD,
        ])->assertStatus(405);
        $this->actingAs($admin)->deleteJson("/api/admin/document-templates/{$template->id}")->assertStatus(405);

        $this->assertFileDoesNotExist(storage_path('tpl-rce-marker'));
        $this->assertDatabaseMissing('document_templates', ['code' => 'evil']);
        $this->assertDatabaseHas('document_templates', ['id' => $template->id]);
    }

    public function test_preview_renders_the_code_owned_form_with_sample_data(): void
    {
        $admin = $this->admin();

        foreach (['leave_sick_personal', 'leave_vacation', 'leave_cancel', 'leave_hajj', 'official_travel', 'leave_military', 'leave_international', 'leave_rehabilitation'] as $code) {
            $template = DocumentTemplate::where('code', $code)->firstOrFail();

            $response = $this->actingAs($admin)->get("/api/admin/document-templates/{$template->id}/preview");

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $code);
        }
    }

    public function test_template_list_shows_which_leave_types_use_each_form(): void
    {
        $vacation = LeaveType::create(['name' => 'ลาพักผ่อน', 'max_days' => 10, 'document_template_code' => 'leave_vacation']);
        // ยังไม่ได้ผูก → ระบบเดาจากชื่อตามเส้นทางเดิม
        $sick = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 60]);

        $templates = collect($this->actingAs($this->admin())->getJson('/api/admin/document-templates')->assertOk()->json())
            ->keyBy('code');

        $this->assertContains($vacation->id, array_column($templates['leave_vacation']['leave_types'], 'id'));
        $this->assertContains($sick->id, array_column($templates['leave_sick_personal']['leave_types'], 'id'));
        $this->assertNotContains($sick->id, array_column($templates['leave_vacation']['leave_types'], 'id'));
        $this->assertTrue($templates['leave_vacation']['has_preview']);
        $this->assertArrayNotHasKey('content_html', $templates['leave_vacation']);
    }

    public function test_users_without_settings_permission_cannot_use_template_endpoints(): void
    {
        $user = User::factory()->create();
        $template = DocumentTemplate::where('code', 'leave_vacation')->firstOrFail();

        $this->actingAs($user)->getJson('/api/admin/document-templates')->assertForbidden();
        $this->actingAs($user)->get("/api/admin/document-templates/{$template->id}/preview")->assertForbidden();
    }
}
