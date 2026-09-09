<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LS-08 — เติมเวอร์ชันแรกให้แม่แบบที่ไม่มีเวอร์ชัน โดยไม่แตะประวัติของเอกสารเก่า
 *
 * ข้อผิดพลาดที่ต้องกันคือการ "ประกาศย้อนหลัง" ว่าแบบฟอร์มมีผลมาตั้งแต่อดีต หรือไปผูก
 * ใบลาเก่าเข้ากับเวอร์ชันที่เพิ่งสร้าง ซึ่งทำให้เอกสารย้อนหลังพิมพ์ออกมาคนละแบบกับฉบับจริง
 */
class DocumentTemplateVersionBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** แม่แบบที่สร้างก่อนมีระบบเวอร์ชัน — เขียนตรงลงตารางเพื่อไม่ให้ hook สร้างเวอร์ชันให้ */
    private function legacyTemplate(string $code): DocumentTemplate
    {
        $id = DB::table('document_templates')->insertGetId([
            'code' => $code, 'name' => 'แม่แบบเก่า '.$code, 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_HTML,
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 8, 'margin_bottom' => 8, 'margin_left' => 12, 'margin_right' => 12,
            'content_html' => '<p>เนื้อหาเดิมของ '.$code.'</p>', 'is_active' => true,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);

        return DocumentTemplate::findOrFail($id);
    }

    public function test_it_creates_the_first_version_from_the_current_content(): void
    {
        $template = $this->legacyTemplate('legacy_form');

        $this->assertSame(0, $template->versions()->count());

        $this->artisan('document:backfill-versions')->assertSuccessful();

        $version = $template->versions()->firstOrFail();

        $this->assertSame(1, $version->version);
        $this->assertSame('<p>เนื้อหาเดิมของ legacy_form</p>', $version->content_html);
        $this->assertSame(DocumentTemplateVersion::STATUS_ACTIVE, $version->status);
    }

    public function test_it_never_claims_the_form_applied_in_the_past(): void
    {
        $template = $this->legacyTemplate('no_backdating_form');

        $this->artisan('document:backfill-versions')->assertSuccessful();

        $this->assertSame(
            now()->toDateString(),
            $template->versions()->firstOrFail()->effective_from->toDateString(),
            'เวอร์ชันที่เติมต้องมีผลตั้งแต่วันที่รันเท่านั้น ไม่ใช่ย้อนหลังตามวันที่สร้างแม่แบบ',
        );
    }

    public function test_it_does_not_attach_old_leaves_to_the_new_version(): void
    {
        $this->legacyTemplate('untouched_leave_form');

        $department = Department::create(['name' => 'แผนกทดสอบเวอร์ชัน']);
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'S']);

        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subMonth()->addDay()->toDateString(),
            'total_days' => 2, 'reason' => 'ป่วย', 'status' => 'Approved', 'approval_level' => 1,
        ]);

        DB::table('leaves')->where('id', $leave->id)->update(['document_template_version_id' => null]);

        $this->artisan('document:backfill-versions')->assertSuccessful();

        $this->assertNull(
            $leave->fresh()->document_template_version_id,
            'ใบลาเก่าต้องไม่ถูกผูกเข้ากับเวอร์ชันที่เพิ่งสร้าง',
        );
    }

    public function test_it_leaves_templates_that_already_have_versions_alone(): void
    {
        $template = DocumentTemplate::create([
            'code' => 'already_versioned_form', 'name' => 'มีเวอร์ชันแล้ว', 'module' => 'leave',
            'document_type' => 'FORM', 'render_mode' => DocumentTemplate::RENDER_HTML,
            'content_html' => '<p>x</p>', 'is_active' => true,
        ]);

        $before = $template->versions()->count();

        $this->artisan('document:backfill-versions')->assertSuccessful();

        $this->assertSame($before, $template->fresh()->versions()->count());
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $template = $this->legacyTemplate('dry_run_form');

        $this->artisan('document:backfill-versions', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $template->versions()->count());
    }

    public function test_running_it_twice_creates_nothing_extra(): void
    {
        $template = $this->legacyTemplate('idempotent_form');

        $this->artisan('document:backfill-versions')->assertSuccessful();
        $this->artisan('document:backfill-versions')->assertSuccessful();

        $this->assertSame(1, $template->versions()->count());
    }
}
