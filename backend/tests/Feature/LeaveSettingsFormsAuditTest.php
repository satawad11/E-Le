<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\DefaultDocumentTemplateService;
use App\Services\LeaveDocumentTemplateResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Regression requirements discovered during the settings/forms/report audit. */
class LeaveSettingsFormsAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 09:00:00'));
        $personnel = PersonnelType::create(['code' => 'audit_settings', 'name' => 'บุคลากรทดสอบ']);
        $this->owner = User::factory()->create(['personnel_type_id' => $personnel->id]);
        $this->admin = User::factory()->create();
        foreach (['leave.policy.manage', 'leave.report.view', 'system.setting.manage'] as $ability) {
            $this->admin->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        $this->type = LeaveType::create(['name' => 'ลาทดสอบตั้งค่า', 'max_days' => 30]);
        $this->policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 30,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);
    }

    private function leave(array $extra = []): Leave
    {
        return Leave::create(array_merge([
            'user_id' => $this->owner->id, 'leave_type_id' => $this->type->id,
            'leave_policy_id' => $this->policy->id, 'start_date' => '2026-09-08',
            'end_date' => '2026-09-09', 'total_days' => 2, 'reason' => 'ทดสอบแบบฟอร์ม',
            'status' => 'Approved',
        ], $extra));
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::create([
            'user_id' => $this->owner->id, 'leave_type_id' => $this->type->id,
            'leave_policy_id' => $this->policy->id, 'year' => 2026,
            'quota' => 30, 'used' => 2, 'reserved' => 0, 'carried_forward' => 0,
        ]);
    }

    public function test_editing_a_policy_must_preserve_the_policy_referenced_by_approved_leaves(): void
    {
        $leave = $this->leave();
        $response = $this->actingAs($this->admin)->putJson('/api/admin/leave-policies/'.$this->policy->id, [
            'personnel_type_id' => $this->policy->personnel_type_id,
            'leave_type_id' => $this->type->id, 'effective_from' => '2025-10-01',
            'annual_quota' => 5, 'period_basis' => 'calendar_year', 'is_active' => true,
        ]);
        // Either reject an edit to an in-use version or create a new version.
        $this->assertContains($response->status(), [200, 201, 422]);
        $this->assertSame('fiscal_year', $leave->fresh()->policy->period_basis);
        $this->assertEquals(30, $leave->fresh()->policy->annual_quota);
    }

    public static function nonCurrentPolicies(): array
    {
        return [
            'inactive' => ['2026-09-07', null, false],
            'expired' => ['2025-10-01', '2026-08-31', true],
            'future in same fiscal year' => ['2026-09-15', null, true],
        ];
    }

    #[DataProvider('nonCurrentPolicies')]
    public function test_non_current_policy_must_not_replace_current_balance(string $from, ?string $to, bool $active): void
    {
        $balance = $this->balance();
        $this->actingAs($this->admin)->postJson('/api/admin/leave-policies', [
            'personnel_type_id' => $this->policy->personnel_type_id,
            'leave_type_id' => $this->type->id, 'effective_from' => $from,
            'effective_to' => $to, 'annual_quota' => 1,
            'period_basis' => 'fiscal_year', 'is_active' => $active,
        ])->assertCreated();
        $this->assertEquals(30, $balance->fresh()->quota);
        $this->assertSame($this->policy->id, $balance->fresh()->leave_policy_id);
    }

    public function test_balance_report_finds_ce_balance_rows_for_selected_buddhist_fiscal_year(): void
    {
        $year = FiscalYear::create(['name' => 'ปีทดสอบ', 'year' => 2569, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_current' => true]);
        $this->balance();
        $this->actingAs($this->admin)->getJson('/api/reporting/leave_balance?fiscal_year_id='.$year->id)
            ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.used', 2);
    }

    public function test_switching_type_template_must_not_change_an_already_pinned_form(): void
    {
        $original = DocumentTemplate::create(['code' => 'leave_sick_personal', 'name' => 'เดิม', 'module' => 'leave', 'content_html' => '<p>ORIGINAL_FORM</p>', 'is_active' => true]);
        DocumentTemplate::create(['code' => 'leave_vacation', 'name' => 'ใหม่', 'module' => 'leave', 'content_html' => '<p>REPLACEMENT_FORM</p>', 'is_active' => true]);
        $this->type->update(['document_template_code' => 'leave_sick_personal']);
        $leave = $this->leave();
        $this->assertSame($original->currentVersion->id, $leave->document_template_version_id);
        $this->type->update(['document_template_code' => 'leave_vacation']);
        $resolver = app(LeaveDocumentTemplateResolver::class);
        $view = $resolver->viewForCode($resolver->templateCodeForLeave($leave->fresh()));
        $this->assertStringContainsString('ORIGINAL_FORM', $resolver->render($leave->fresh(), $view, []));
    }

    /**
     * LS-07 — ชนิดไฟล์ที่ประกาศ นามสกุลในชื่อไฟล์ และเนื้อไฟล์จริง ต้องเป็นเรื่องเดียวกัน
     *
     * ข้อบกพร่องเดิมคือ "หน้าจอตั้งชื่อ .docx แต่เซิร์ฟเวอร์ส่ง Word แบบ HTML" ซึ่งเป็นการ
     * ไม่ตรงกันของสัญญาสองฝั่ง ไม่ใช่การขาดรูปแบบใดรูปแบบหนึ่ง — การแก้จึงยึดให้เซิร์ฟเวอร์
     * เป็นผู้ประกาศทั้งชนิดและชื่อไฟล์ และหน้าจอใช้ค่าจาก header นั้น (ดู ADR-WORD-20260909)
     *
     * เทสต์นี้ตรึง "ความสอดคล้อง" ไว้ ไม่ได้ตรึงว่าต้องเป็น .doc ตลอดไป — ถ้าวันหนึ่ง
     * เปลี่ยนไปออกเป็น DOCX จริง เทสต์นี้ยังต้องผ่านโดยไม่ต้องแก้ฝั่งหน้าจอ
     */
    public function test_word_download_declares_a_type_that_matches_the_file_it_sends(): void
    {
        $leave = $this->leave(['cancellation_status' => 'Pending', 'cancellation_reason' => 'เหตุยกเลิกเฉพาะทดสอบ']);

        $response = $this->actingAs($this->owner)->get('/api/leaves/'.$leave->id.'/word?form=cancel')->assertOk();

        $contentType = $response->headers->get('Content-Type');
        $disposition = $response->headers->get('Content-Disposition');

        $formats = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-word' => 'doc',
            'application/msword' => 'doc',
        ];

        $mime = strtok((string) $contentType, ';');

        $this->assertArrayHasKey($mime, $formats, 'ต้องประกาศชนิดไฟล์ Word ที่ระบบรองรับจริง');
        $this->assertStringContainsString('.'.$formats[$mime], (string) $disposition, 'นามสกุลในชื่อไฟล์ต้องตรงกับชนิดที่ประกาศ');
        $this->assertStringStartsWith('ใบยกเลิกวันลา_', $this->filenameOf($disposition), 'ใบยกเลิกต้องแยกชื่อไฟล์จากใบลาปกติ');

        $body = $response->getContent();

        if ($formats[$mime] === 'docx') {
            $this->assertStringStartsWith('PK', $body, 'DOCX ต้องเป็นไฟล์บีบอัด ไม่ใช่ HTML');
        } else {
            $this->assertStringContainsString('<html', $body, 'Word แบบ HTML ต้องมีเนื้อ HTML จริง');
        }
    }

    /** ชื่อไฟล์จริงอยู่ใน `filename*` ตาม RFC 6266 ส่วน `filename` เป็นชื่อสำรอง ASCII */
    private function filenameOf(?string $disposition): string
    {
        if (preg_match("/filename\*=UTF-8''([^;]+)/i", (string) $disposition, $matches)) {
            return rawurldecode(trim($matches[1]));
        }

        preg_match('/filename="?([^";]+)"?/i', (string) $disposition, $matches);

        return $matches[1] ?? '';
    }

    public function test_fiscal_year_referenced_by_a_leave_cannot_be_deleted(): void
    {
        $year = FiscalYear::create(['name' => 'ปีทดสอบ', 'year' => 2569, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_current' => true]);
        $leave = $this->leave(['fiscal_year_id' => $year->id]);
        $this->actingAs($this->admin)->deleteJson('/api/admin/fiscal-years/'.$year->id)->assertUnprocessable();
        $this->assertSame($year->id, $leave->fresh()->fiscal_year_id);
    }

    public static function leaveForms(): array
    {
        return [
            'sick' => ['ลาป่วย', 'leave_sick_personal', false],
            'maternity' => ['ลาคลอดบุตร', 'leave_sick_personal', false],
            'paternity' => ['ลาไปช่วยเหลือภริยาที่คลอดบุตร', 'leave_paternity', false],
            'personal' => ['ลากิจส่วนตัว', 'leave_sick_personal', false],
            'vacation' => ['ลาพักผ่อน', 'leave_vacation', false],
            'ordination' => ['ลาอุปสมบท', 'leave_ordination', false],
            'hajj' => ['ลาไปประกอบพิธีฮัจญ์', 'leave_hajj', false],
            'military fallback' => ['ลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล', null, false],
            'study' => ['ลาไปศึกษาต่อ ดูงาน ฝึกอบรม', 'leave_study', false],
            'international fallback' => ['ลาไปปฏิบัติงานในองค์การระหว่างประเทศ', null, false],
            'spouse' => ['ลาติดตามคู่สมรส', 'leave_spouse', false],
            'rehabilitation fallback' => ['ลาไปฟื้นฟูสมรรถภาพด้านอาชีพ', null, false],
            'cancellation' => ['ลาป่วย', 'leave_sick_personal', true],
        ];
    }

    /** Checks renderability only; does not certify statutory wording or visual layout. */
    #[DataProvider('leaveForms')]
    public function test_each_leave_type_and_cancellation_can_render_a_pdf(string $name, ?string $code, bool $cancel): void
    {
        app(DefaultDocumentTemplateService::class)->sync();
        $this->type->update(['name' => $name, 'document_template_code' => $code]);
        $leave = $this->leave($cancel ? ['cancellation_status' => 'Pending', 'cancellation_reason' => 'เหตุยกเลิกทดสอบ'] : []);
        $response = $this->actingAs($this->owner)->getJson('/api/leaves/'.$leave->id.'/pdf'.($cancel ? '?form=cancel' : ''));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
