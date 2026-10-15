<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\FiscalYear;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveEvidence;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleAssignment;
use App\Models\Time\WorkScheduleDay;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Services\LeaveDocumentTemplateResolver;
use App\Services\LeaveService;
use App\Services\Time\TimeService;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-07 — Leave Modernization
 *
 * ตรวจกฎธุรกิจสำคัญตามที่โจทย์กำหนด: entitlement · used/reserved balance ·
 * overlapping leave · half-day · fiscal year · evidence requirement ·
 * cancellation · approval history · IDOR · scope leakage
 *
 * เน้นสิ่งที่ **ต้องไม่พัง** เป็นหลัก เพราะเฟสนี้คือการต่อโมดูลลาเข้ากับ Core ใหม่
 * ไม่ใช่การเขียนใหม่
 */
class LeaveModernizationTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $sickLeave;

    private PersonnelType $personnelType;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'leave.management.manage', 'leave.approval.hr', 'leave.approval.dept',
            'leave.approval.final', 'leave.report.view',
        ] as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        $this->personnelType = PersonnelType::create(['code' => 'teacher', 'name' => 'ครู', 'is_active' => true]);
        $this->sickLeave = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 60]);
    }

    private function policy(array $overrides = []): LeavePolicy
    {
        return LeavePolicy::create(array_merge([
            'personnel_type_id' => $this->personnelType->id,
            'leave_type_id' => $this->sickLeave->id,
            'effective_from' => '2000-01-01',
            'annual_quota' => 30,
            'exclude_holidays' => true,
            'allow_half_day' => true,
            'allow_past_dates' => true,
            'notice_period_days' => 0,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ], $overrides));
    }

    private function staff(): User
    {
        $user = User::factory()->create([
            'personnel_type_id' => $this->personnelType->id,
            'department_id' => Department::create(['name' => 'ฝ่ายทดสอบ'])->id,
        ])->fresh();

        /*
         * ตั้งวันเริ่มรับราชการไว้ในอดีตให้ชัดเจน
         *
         * เดิมไม่ได้ตั้ง จึงตกไปใช้ `created_at` = วันที่รันเทสต์ พอวันจริงเลย
         * `start_date` ที่ hardcode ไว้ (2026-08-17) วันเริ่มรับราชการจะกลาย
         * เป็นวันหลังวันลา แล้วชน `LeavePolicyService::assertEligible`
         * ("อายุราชการ/อายุงานยังไม่ครบตามหลักเกณฑ์") ทำให้เทสต์ทั้งคลาส
         * ตกพร้อมกันเมื่อเวลาผ่านไป
         */
        $user->personnel?->update(['effective_from' => '2000-01-01']);

        return $user->fresh();
    }

    private function service(): LeaveService
    {
        app(TimeService::class)->flush();

        return app(LeaveService::class);
    }

    private function request(User $user, array $overrides = []): Leave
    {
        return $this->service()->createLeaveRequest(array_merge([
            'leave_type_id' => $this->sickLeave->id,
            'start_date' => '2026-08-17',   // จันทร์
            'end_date' => '2026-08-19',     // พุธ
            'reason' => 'ทดสอบ',
        ], $overrides), $user->id, null);
    }

    // ------------------------------------------------------- entitlement

    public function test_a_leave_within_quota_is_accepted_and_reserves_balance(): void
    {
        $this->policy();
        $user = $this->staff();

        $leave = $this->request($user);

        $this->assertSame(3.0, (float) $leave->total_days);
        $this->assertSame('Pending', $leave->status);

        $balance = $leave->user->leaveBalances()->first()
            ?? LeaveBalance::where('user_id', $user->id)->first();

        $this->assertSame(3.0, (float) $balance->reserved, 'ใบลาที่รออนุมัติต้องกันโควตาไว้');
        $this->assertSame(0.0, (float) $balance->used, 'ยังไม่อนุมัติจึงยังไม่นับเป็นใช้ไปแล้ว');
    }

    public function test_a_leave_beyond_the_quota_is_refused(): void
    {
        $this->policy(['annual_quota' => 2]);
        $user = $this->staff();

        $this->expectException(ValidationException::class);
        $this->request($user);   // ขอ 3 วันจากโควตา 2 วัน
    }

    /**
     * โควตาต้องนับรวมใบที่ยังรออนุมัติ มิฉะนั้นยื่นซ้ำ ๆ จะทะลุโควตาได้
     */
    public function test_pending_leaves_count_against_the_quota(): void
    {
        $this->policy(['annual_quota' => 4]);
        $user = $this->staff();

        $this->request($user);   // 3 วัน · reserved = 3

        $this->expectException(ValidationException::class);
        $this->request($user, ['start_date' => '2026-08-24', 'end_date' => '2026-08-26']);
    }

    // --------------------------------------------------------- overlap

    public function test_an_overlapping_leave_is_refused(): void
    {
        $this->policy();
        $user = $this->staff();

        $this->request($user);

        $this->expectException(ValidationException::class);
        $this->request($user, ['start_date' => '2026-08-18', 'end_date' => '2026-08-20']);
    }

    public function test_a_cancelled_leave_does_not_block_a_new_one(): void
    {
        $this->policy();
        $user = $this->staff();

        $first = $this->request($user);
        $first->update(['status' => 'Cancelled']);

        $second = $this->request($user, ['start_date' => '2026-08-18', 'end_date' => '2026-08-20']);

        $this->assertSame('Pending', $second->status);
    }

    // -------------------------------------------------------- half-day

    public function test_a_half_day_leave_counts_as_half_a_day(): void
    {
        $this->policy();
        $user = $this->staff();

        $leave = $this->request($user, [
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-17',
            'leave_period' => 'morning',
        ]);

        $this->assertSame(0.5, (float) $leave->total_days);
    }

    public function test_a_half_day_leave_must_be_a_single_day(): void
    {
        $this->policy();
        $user = $this->staff();

        $this->expectException(ValidationException::class);
        $this->request($user, [
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-18',
            'leave_period' => 'morning',
        ]);
    }

    // ------------------------------------------- Time Foundation (LEGACY-06)

    public function test_holidays_and_weekends_are_excluded_from_the_day_count(): void
    {
        $this->policy();
        $user = $this->staff();

        Holiday::create(['date' => '2026-08-18', 'name' => 'วันหยุดทดสอบ', 'is_active' => true]);

        // จ.17 – ศ.21 = 5 วันธรรมดา ลบวันหยุด 1 วัน = 4
        $leave = $this->request($user, ['start_date' => '2026-08-17', 'end_date' => '2026-08-21']);

        $this->assertSame(4.0, (float) $leave->total_days);
    }

    /**
     * LEGACY-07 — ตารางเวลาเฉพาะบุคคลต้องมีผลกับการนับวันลา
     *
     * ผู้ที่ทำงานเสาร์ตามตารางของตน ต้องถูกนับวันเสาร์เป็นวันลาด้วย
     * เดิม `calculateTotalDays` ไม่รู้จักผู้ใช้เลย จึงใช้ปฏิทินกลางเสมอ
     */
    public function test_a_personal_work_schedule_changes_the_day_count(): void
    {
        $this->policy();
        $user = $this->staff();

        $schedule = WorkSchedule::create([
            'code' => 'six-day', 'name' => 'ทำงานหกวัน', 'timezone' => 'Asia/Bangkok',
            'grace_minutes' => 0, 'early_leave_grace_minutes' => 0, 'is_default' => false, 'is_active' => true,
        ]);

        foreach (range(0, 6) as $dayOfWeek) {
            $working = $dayOfWeek >= 1 && $dayOfWeek <= 6;   // จันทร์–เสาร์
            WorkScheduleDay::create([
                'work_schedule_id' => $schedule->id,
                'day_of_week' => $dayOfWeek,
                'is_working_day' => $working,
                'start_time' => $working ? '08:30:00' : null,
                'end_time' => $working ? '16:30:00' : null,
            ]);
        }

        WorkScheduleAssignment::create([
            'work_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'effective_from' => '2000-01-01',
        ]);

        // จ.17 – ส.22 = 6 วันสำหรับคนทำงานหกวัน (คนทั่วไปจะได้ 5)
        $leave = $this->request($user, ['start_date' => '2026-08-17', 'end_date' => '2026-08-22']);

        $this->assertSame(6.0, (float) $leave->total_days, 'ตารางเฉพาะบุคคลต้องมีผลกับการนับวันลา');
    }

    // ------------------------------------------------------ fiscal year

    public function test_the_balance_is_keyed_by_fiscal_year(): void
    {
        FiscalYear::create(['name' => 'ปีงบ 2569', 'year' => 2569, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_current' => true]);
        FiscalYear::create(['name' => 'ปีงบ 2570', 'year' => 2570, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_current' => false]);

        $this->policy();
        $user = $this->staff();

        $before = $this->request($user, ['start_date' => '2026-09-28', 'end_date' => '2026-09-29']);
        $after = $this->request($user, ['start_date' => '2026-10-05', 'end_date' => '2026-10-06']);

        $balances = LeaveBalance::where('user_id', $user->id)->pluck('year')->sort()->values()->all();

        $this->assertSame([2026, 2027], $balances, 'ใบลาคนละปีงบต้องตัดคนละ balance');
        $this->assertNotSame($before->fiscal_year_id, $after->fiscal_year_id);
    }

    // ------------------------------------------------ evidence requirement

    public function test_evidence_is_required_when_the_policy_says_so(): void
    {
        $this->policy(['attachment_required_after_days' => 2, 'required_evidence_type' => 'medical_certificate']);
        $user = $this->staff();

        $this->expectException(ValidationException::class);
        $this->request($user);   // 3 วัน เกินเกณฑ์ 2 วัน แต่ไม่แนบหลักฐาน
    }

    public function test_a_leave_with_evidence_is_accepted_and_stored_privately(): void
    {
        Storage::fake('local');
        $this->policy(['attachment_required_after_days' => 2, 'required_evidence_type' => 'medical_certificate']);
        $user = $this->staff();

        $leave = $this->service()->createLeaveRequest([
            'leave_type_id' => $this->sickLeave->id,
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-19',
            'reason' => 'ป่วย',
        ], $user->id, UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'));

        $evidence = LeaveEvidence::where('leave_id', $leave->id)->first();

        $this->assertNotNull($evidence);
        $this->assertStringStartsWith('private/', $evidence->storage_path, 'หลักฐานต้องอยู่ใน private storage');
        $this->assertNotNull($evidence->sha256, 'ต้องเก็บ hash ไว้ตรวจความถูกต้อง');
    }

    // -------------------------------------------------- approval history

    public function test_the_approval_chain_is_snapshotted_on_creation(): void
    {
        $hr = Role::firstOrCreate(['name' => 'HR Head', 'guard_name' => 'web']);
        $director = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);
        ApprovalStep::create(['step_order' => 1, 'role_id' => $hr->id, 'name' => 'ขั้นที่ 1']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $director->id, 'name' => 'ขั้นที่ 2']);

        $this->policy();
        $leave = $this->request($this->staff());

        $this->assertSame(2, $leave->approvals()->count());
        $this->assertSame([1, 2], $leave->approvals()->orderBy('step_order')->pluck('step_order')->all());
    }

    /**
     * LEGACY-07 — ใบลาใหม่ต้องมี workflow instance (shadow) ผูกไว้
     */
    public function test_a_new_leave_opens_a_shadow_workflow_instance(): void
    {
        $hr = Role::firstOrCreate(['name' => 'HR Head', 'guard_name' => 'web']);
        ApprovalStep::create(['step_order' => 1, 'role_id' => $hr->id, 'name' => 'ขั้นที่ 1']);
        app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();

        $this->policy();
        $leave = $this->request($this->staff());

        $instance = WorkflowInstance::where('subject_id', $leave->id)
            ->where('subject_type', $leave->getMorphClass())
            ->first();

        $this->assertNotNull($instance, 'ใบลาใหม่ต้องมี workflow instance');
        $this->assertTrue($instance->is_shadow, 'ต้องยังเป็น shadow — ตรรกะเดิมยังตัดสินจริง');
        $this->assertNotNull($instance->workflow_version_id, 'ต้อง pin เวอร์ชันไว้');
    }

    public function test_a_failing_workflow_never_blocks_a_leave_request(): void
    {
        // ไม่มี workflow version เลย — การเปิด instance ต้องล้มเงียบ ๆ
        $this->policy();

        $leave = $this->request($this->staff());

        $this->assertSame('Pending', $leave->status, 'การยื่นใบลาต้องสำเร็จแม้ workflow จะเปิดไม่ได้');
    }

    // ------------------------------------------- template version (07-1)

    /**
     * `DocumentTemplate` สร้าง version 1 ให้เองอยู่แล้วผ่าน model event
     * จึงใช้ตัวนั้นแทนการสร้างซ้ำ (ไม่งั้นชน unique constraint)
     *
     * ขยับ `effective_from` ให้ย้อนหลังเพื่อให้ครอบใบลาที่เทสต์สร้าง
     */
    private function makeTemplate(string $code, string $html): DocumentTemplateVersion
    {
        $template = DocumentTemplate::create([
            'code' => $code, 'name' => $code, 'module' => 'leave',
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 15, 'margin_bottom' => 15, 'margin_left' => 20, 'margin_right' => 20,
            'content_html' => $html, 'is_active' => true,
        ]);

        $version = $template->versions()->firstOrFail();
        $version->update(['effective_from' => '2000-01-01']);

        return $version->fresh();
    }

    public function test_a_leave_pins_the_template_of_its_own_form(): void
    {
        // สองแบบฟอร์ม — ของเดิมจะ pin ตัว id น้อยสุดเสมอไม่ว่าจะลาประเภทใด
        $this->makeTemplate('leave_sick_personal', 'ใบลาป่วย');
        $this->makeTemplate('leave_vacation', 'ใบลาพักผ่อน');

        $vacationType = LeaveType::create(['name' => 'ลาพักผ่อน', 'max_days' => 10]);
        LeavePolicy::create([
            'personnel_type_id' => $this->personnelType->id, 'leave_type_id' => $vacationType->id,
            'effective_from' => '2000-01-01', 'annual_quota' => 10, 'exclude_holidays' => true,
            'allow_half_day' => true, 'allow_past_dates' => true, 'notice_period_days' => 0,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);

        $leave = $this->request($this->staff(), ['leave_type_id' => $vacationType->id]);

        $this->assertSame(
            'leave_vacation',
            DocumentTemplateVersion::find($leave->document_template_version_id)?->template->code,
            'ใบลาพักผ่อนต้อง pin แบบฟอร์มลาพักผ่อน ไม่ใช่แบบฟอร์มลาป่วย',
        );
    }

    /**
     * หัวใจของ LEAVE-003 — แก้เทมเพลตแล้วเอกสารเดิมต้องพิมพ์ออกมาเหมือนเดิม
     */
    public function test_editing_a_template_does_not_change_an_existing_leave(): void
    {
        $version = $this->makeTemplate('leave_sick_personal', 'ฉบับเดิม');

        $this->policy();
        $leave = $this->request($this->staff());

        $this->assertSame($version->id, $leave->document_template_version_id);

        // ผู้ดูแลออกเวอร์ชันใหม่ภายหลัง
        $version->update(['effective_to' => '2026-08-16', 'is_active' => false]);
        DocumentTemplateVersion::create([
            'document_template_id' => $version->document_template_id, 'version' => 2,
            'paper_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 15, 'margin_bottom' => 15, 'margin_left' => 20, 'margin_right' => 20,
            'content_html' => 'ฉบับใหม่', 'effective_from' => '2026-08-17', 'is_active' => true,
        ]);

        // TPL-SEC-01: เนื้อหา HTML มาจากไฟล์ blade ในโค้ดแล้ว — สิ่งที่ต้องคงเดิมคือ "ใบลานี้ผูกกับเวอร์ชันใด"
        $this->assertSame(
            $version->id,
            app(LeaveDocumentTemplateResolver::class)->versionFor($leave->fresh(), 'pdf.leave')?->id,
            'ใบลาเดิมต้องยังอ้างเวอร์ชันที่ pin ไว้',
        );
    }

    // ------------------------------------------ 07-3 จำนวนครั้ง vs จำนวนวัน

    /**
     * LEAVE-001 — รายงานต้องนับ "จำนวนครั้ง" แยกจาก "จำนวนวัน"
     *
     * ลา 3 ครั้ง รวม 6 วัน ต้องไม่ถูกรายงานเป็น 6 ครั้งหรือ 3 วัน
     */
    public function test_reports_count_occurrences_separately_from_days(): void
    {
        $this->policy();
        $user = $this->staff();

        $this->request($user, ['start_date' => '2026-08-17', 'end_date' => '2026-08-18']);   // 2 วัน
        $this->request($user, ['start_date' => '2026-08-24', 'end_date' => '2026-08-26']);   // 3 วัน
        $this->request($user, ['start_date' => '2026-08-31', 'end_date' => '2026-08-31']);   // 1 วัน

        $stats = Leave::where('user_id', $user->id)
            ->selectRaw('COUNT(*) as times, SUM(total_days) as days')
            ->first();

        $this->assertSame(3, (int) $stats->times, 'จำนวนครั้งต้องเป็น 3');
        $this->assertSame(6.0, (float) $stats->days, 'จำนวนวันต้องเป็น 6');
    }

    // ------------------------------------ 07-5 ฐานปีของเลขที่เอกสาร

    /**
     * SARABAN-001 / Open Question #6 — ฐานปีของเลขที่ใบลาต้องตรงกับ
     * `period_basis` ของนโยบาย และสอดคล้องกับปีงบประมาณแบบเดียวกับสารบรรณ
     * เมื่อใช้ฐานปีงบ
     */
    public function test_the_document_number_year_follows_the_policy_period_basis(): void
    {
        FiscalYear::create(['name' => 'ปีงบ 2570', 'year' => 2570, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_current' => true]);

        $this->policy();   // period_basis = fiscal_year
        $user = $this->staff();

        // 5 ต.ค. 2026 อยู่ในปีงบ 2570 (สิ้นสุด ค.ศ.2027 → 2027 + 543 = 2570)
        $leave = $this->request($user, ['start_date' => '2026-10-05', 'end_date' => '2026-10-06']);

        $this->assertStringContainsString('-2570-', (string) $leave->document_number, 'เลขที่ใบลาต้องใช้ปีงบประมาณ พ.ศ.');
    }

    public function test_a_calendar_year_policy_numbers_by_calendar_year(): void
    {
        FiscalYear::create(['name' => 'ปีงบ 2570', 'year' => 2570, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_current' => true]);

        $this->policy(['period_basis' => 'calendar_year']);
        $user = $this->staff();

        // ต.ค. 2026 = ปีปฏิทิน 2026 → 2569 แม้จะอยู่ในปีงบ 2570
        $leave = $this->request($user, ['start_date' => '2026-10-05', 'end_date' => '2026-10-06']);

        $this->assertStringContainsString('-2569-', (string) $leave->document_number, 'นโยบายฐานปีปฏิทินต้องใช้ปีปฏิทิน');
    }

    public function test_a_form_without_a_managed_template_still_renders(): void
    {
        $this->policy();
        $leave = $this->request($this->staff());

        // `pdf.leave_hajj` ไม่มีแถวในตารางเทมเพลต — ต้องถอยไปใช้ blade ไม่ใช่ล้ม
        $this->assertNull(app(LeaveDocumentTemplateResolver::class)->versionFor($leave, 'pdf.leave_hajj'));
    }
}
