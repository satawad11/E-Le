<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeaveDocumentTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ค่าที่ตั้งในหน้าตั้งค่าระบบต้องมีผลกับเอกสารที่พิมพ์ออกมาจริง
 *
 * ข้อบกพร่องเดิม: ระยะขอบกระดาษและแบบอักษรถูกบันทึกได้ แต่แม่แบบทุกฉบับเขียน
 * `@page { margin: 8mm 12mm }` และชื่อฟอนต์ไว้ตายตัวในตัวเอง ผู้ดูแลจึงแก้ค่าแล้ว
 * เอกสารออกมาเหมือนเดิมทุกครั้งโดยไม่มีอะไรบอกว่าค่าที่ตั้งไม่ถูกใช้
 */
class LeaveDocumentPageStyleTest extends TestCase
{
    use RefreshDatabase;

    private function leave(): Leave
    {
        $department = OrgUnit::factory()->create(['name' => 'แผนกทดสอบสไตล์เอกสาร']);
        $user = User::factory()->create(['org_unit_id' => $department->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'S']);

        return Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => 'Approved', 'approval_level' => 1,
        ])->fresh();
    }

    private function render(Leave $leave): string
    {
        return app(LeaveDocumentTemplateResolver::class)->render($leave, 'pdf.leave', []);
    }

    public function test_the_margins_from_system_settings_reach_the_printed_form(): void
    {
        Setting::create(['key' => 'margin_top', 'value' => '2.5', 'type' => 'string']);
        Setting::create(['key' => 'margin_bottom', 'value' => '2', 'type' => 'string']);
        Setting::create(['key' => 'margin_left', 'value' => '3', 'type' => 'string']);
        Setting::create(['key' => 'margin_right', 'value' => '1.5', 'type' => 'string']);

        $html = $this->render($this->leave());

        // ตั้งเป็นเซนติเมตรในหน้าจอ แต่ CSS ของเอกสารเป็นมิลลิเมตร
        $this->assertStringContainsString('@page{margin:25mm 15mm 20mm 30mm;}', $html);
    }

    public function test_the_font_from_system_settings_is_embedded_with_a_file_that_exists(): void
    {
        Setting::create(['key' => 'default_font', 'value' => 'TH SarabunIT๙', 'type' => 'string']);

        $html = $this->render($this->leave());

        $this->assertStringContainsString('THSarabunIT๙.ttf', $html);
        $this->assertStringContainsString("font-family:'THSarabunIT9'", $html);
    }

    public function test_every_selectable_font_has_files_installed(): void
    {
        foreach (LeaveDocumentTemplateResolver::FONT_FILES as $name => $font) {
            foreach ([$font['regular'], $font['bold']] as $file) {
                $this->assertFileExists(
                    public_path('fonts/THSarabunNew/'.$file),
                    "ฟอนต์ {$name} ถูกให้เลือกในหน้าตั้งค่าแต่ไม่มีไฟล์ในระบบ",
                );
            }
        }

        $allowed = collect(config('system_settings.rules.default_font'))
            ->first(fn (string $rule) => str_starts_with($rule, 'in:'));

        $this->assertSame(
            array_keys(LeaveDocumentTemplateResolver::FONT_FILES),
            explode(',', substr((string) $allowed, 3)),
            'ตัวเลือกฟอนต์ในหน้าตั้งค่าต้องตรงกับฟอนต์ที่ติดตั้งไว้จริง',
        );
    }

    public function test_the_form_specific_margins_of_a_template_version_win_over_the_global_setting(): void
    {
        Setting::create(['key' => 'margin_top', 'value' => '2.5', 'type' => 'string']);

        $template = DocumentTemplate::create([
            'code' => 'leave_sick_personal', 'name' => 'แบบใบลาป่วย', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_HTML,
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 5, 'margin_bottom' => 6, 'margin_left' => 7, 'margin_right' => 8,
            'content_html' => '<p>แบบฟอร์มของหน่วยงาน</p>', 'is_active' => true,
        ]);

        $leave = $this->leave();
        $leave->update(['document_template_version_id' => $template->versions()->firstOrFail()->id]);

        $html = $this->render($leave->fresh());

        $this->assertStringContainsString('@page{margin:5mm 8mm 6mm 7mm;}', $html);
    }
}
