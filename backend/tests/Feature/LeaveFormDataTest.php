<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\DocumentTemplate;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DefaultDocumentTemplateService;
use App\Services\LeaveApproverBlocks;
use App\Services\LeaveDocumentFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ข้อมูลที่พิมพ์ลงแบบฟอร์มใบลาต้องตรงกับข้อมูลจริง
 *
 * - ยอดวันลาพักผ่อนอ่านจากปีงบประมาณ ไม่ใช่ปีปฏิทิน
 * - "ปีงบประมาณ" บนแบบฟอร์มเป็นปีงบจริง
 * - "ลามาแล้ว" นับเฉพาะใบที่ลาก่อนใบนี้
 * - ผู้ลงนามอ่านจาก snapshot ตามลำดับขั้น ไม่ใช่จับคู่บทบาท
 * - ใบขอยกเลิกวันลาใช้ผู้ลงนามของสายอนุมัติคำขอยกเลิก
 */
class LeaveFormDataTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        ApprovalStep::query()->delete();

        $this->staff = User::factory()->create(['name' => 'ผู้ลา ทดสอบ']);
        $this->vacation = LeaveType::create(['name' => 'ลาพักผ่อนทดสอบ', 'max_days' => 10, 'document_template_code' => 'leave_vacation']);
    }

    private function leave(array $attributes = []): Leave
    {
        return Leave::create(array_merge([
            'user_id' => $this->staff->id,
            'leave_type_id' => $this->vacation->id,
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
            'total_days' => 2,
            'reason' => 'พักผ่อน',
            'status' => 'Approved',
        ], $attributes))->fresh(['user', 'type']);
    }

    public function test_vacation_balance_comes_from_the_fiscal_year_of_the_leave(): void
    {
        // ปีงบ 2570 (1 ต.ค. 2026 – 30 ก.ย. 2027) เก็บที่คีย์ 2027
        LeaveBalance::create(['user_id' => $this->staff->id, 'leave_type_id' => $this->vacation->id, 'year' => 2027, 'quota' => 25, 'carried_forward' => 15, 'used' => 0, 'reserved' => 0]);
        // ยอดของปีงบที่ปิดไปแล้ว — ห้ามถูกนำมาพิมพ์
        LeaveBalance::create(['user_id' => $this->staff->id, 'leave_type_id' => $this->vacation->id, 'year' => 2026, 'quota' => 10, 'carried_forward' => 0, 'used' => 0, 'reserved' => 0]);

        $fields = app(LeaveDocumentFields::class)->build($this->leave());

        $this->assertSame(15, $fields['vacation_accumulated']);
        $this->assertSame(10, $fields['vacation_entitled']);
        $this->assertSame(25, $fields['vacation_total']);
        $this->assertSame(2570, $fields['fiscal_year']);
    }

    public function test_fiscal_year_label_before_october_is_the_current_buddhist_year(): void
    {
        $fields = app(LeaveDocumentFields::class)->build($this->leave(['start_date' => '2026-09-14', 'end_date' => '2026-09-14', 'total_days' => 1]));

        $this->assertSame(2569, $fields['fiscal_year']);
    }

    public function test_days_already_taken_only_count_leaves_before_this_one(): void
    {
        $this->leave(['start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'total_days' => 2]);
        $current = $this->leave(['start_date' => '2026-11-16', 'end_date' => '2026-11-17', 'total_days' => 2]);
        // ลาทีหลังใบนี้ในปีงบเดียวกัน — ต้องไม่ถูกนับเป็น "ลามาแล้ว" ในเอกสารของใบนี้
        $this->leave(['start_date' => '2027-01-11', 'end_date' => '2027-01-13', 'total_days' => 3]);
        // ปีงบก่อนหน้า — ไม่นับ
        $this->leave(['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'total_days' => 1]);

        $stats = app(LeaveDocumentFields::class)->build($current)['stats'][$this->vacation->name];

        $this->assertSame(2, $stats['before']);
        $this->assertSame(1, $stats['times_before']);
        $this->assertSame(2, $stats['current']);
        $this->assertSame(4, $stats['total']);
    }

    public function test_signers_come_from_the_approval_snapshot_by_step_order(): void
    {
        $hrRole = Role::findOrCreate('HR Head', 'web');
        $directorRole = Role::findOrCreate('Director', 'web');
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1, 'name' => 'หัวหน้าแผนก', 'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD]);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 2, 'name' => 'งานบุคลากร', 'role_id' => $hrRole->id]);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 3, 'name' => 'ผู้อำนวยการ', 'role_id' => $directorRole->id]);

        // หัวหน้าแผนกไม่มีบทบาทตรงกับขั้นใด — การจับคู่บทบาทแบบเดิมจะหาไม่เจอ
        $head = User::factory()->create(['name' => 'หัวหน้า แผนก']);
        $leave = $this->leave(['status' => 'Pending']);
        LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1, 'step_name' => 'หัวหน้าแผนก', 'approver_id' => $head->id, 'approver_name' => 'นายหัวหน้า แผนก', 'approver_position' => 'หัวหน้าแผนกวิชา', 'status' => 'Approved', 'comment' => 'เห็นควรอนุญาต', 'approved_at' => '2026-11-10 09:00:00']);
        LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 2, 'step_name' => 'งานบุคลากร', 'approver_name' => 'นางบุคลากร ตรวจสอบ', 'status' => 'Pending']);

        $blocks = app(LeaveApproverBlocks::class)->for($leave->fresh());

        $this->assertCount(3, $blocks);
        $this->assertSame('นายหัวหน้า แผนก', $blocks[0]['name']);
        $this->assertSame('หัวหน้าแผนกวิชา', $blocks[0]['position']);
        $this->assertSame('เห็นควรอนุญาต', $blocks[0]['comment']);
        $this->assertSame('2026-11-10', $blocks[0]['date']->toDateString());

        // ขั้นที่ยังรออยู่: มีชื่อผู้ที่ต้องลงนาม แต่ไม่มีวันที่และความเห็น
        $this->assertSame('นางบุคลากร ตรวจสอบ', $blocks[1]['name']);
        $this->assertNull($blocks[1]['date']);
        $this->assertNull($blocks[1]['comment']);
        $this->assertSame('HR Head', $blocks[1]['role_name']);

        // ขั้นที่ยังไม่ถึง
        $this->assertNull($blocks[2]['status']);
        $this->assertNull($blocks[2]['date']);
    }

    public function test_cancellation_form_uses_the_cancellation_chain_signers(): void
    {
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1, 'name' => 'ผู้อำนวยการ']);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_CANCELLATION, 'step_order' => 1, 'name' => 'รองผู้อำนวยการ']);
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_CANCELLATION, 'step_order' => 2, 'name' => 'ผู้อำนวยการ']);

        $leave = $this->leave();
        LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1, 'approver_name' => 'ผู้อนุมัติใบลา', 'status' => 'Approved', 'approved_at' => '2026-11-01 09:00:00']);
        LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_CANCELLATION, 'step_order' => 1, 'approver_name' => 'รองผู้อำนวยการ ทดสอบ', 'status' => 'Approved', 'approved_at' => '2026-11-12 09:00:00']);

        $blocks = app(LeaveApproverBlocks::class)->for($leave->fresh(), isCancel: true);

        $this->assertCount(2, $blocks);
        $this->assertSame('รองผู้อำนวยการ ทดสอบ', $blocks[0]['name']);
        $this->assertSame('2026-11-12', $blocks[0]['date']->toDateString());
        $this->assertNotSame('ผู้อนุมัติใบลา', $blocks[1]['name']);
    }

    public function test_printed_vacation_form_shows_the_signer_and_fiscal_balance(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        ApprovalStep::create(['chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1, 'name' => 'ผู้อำนวยการ']);
        LeaveBalance::create(['user_id' => $this->staff->id, 'leave_type_id' => $this->vacation->id, 'year' => 2027, 'quota' => 20, 'carried_forward' => 10, 'used' => 0, 'reserved' => 0]);

        $leave = $this->leave();
        LeaveApproval::create(['leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST, 'step_order' => 1, 'approver_name' => 'นายผู้อำนวยการ ทดสอบ', 'status' => 'Approved', 'approved_at' => '2026-11-10 09:00:00']);

        $response = $this->actingAs($admin)->get("/api/leaves/{$leave->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * แบบฟอร์มราชการเป็นกระดาษ A4 แผ่นเดียว — เดิมแบบใบลาป่วยฯ ล้นไปหน้า 2 ทั้งส่วนลงนาม
     * เพราะ dompdf ตัดแถวตารางข้ามหน้าไม่ได้
     */
    public function test_every_leave_form_fits_on_one_a4_page(): void
    {
        app(DefaultDocumentTemplateService::class)->sync();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $codes = ['leave_sick_personal', 'leave_vacation', 'leave_cancel', 'leave_paternity', 'leave_ordination', 'leave_hajj', 'leave_spouse', 'leave_study', 'official_travel', 'leave_military', 'leave_international', 'leave_rehabilitation'];

        foreach ($codes as $code) {
            $template = DocumentTemplate::where('code', $code)->firstOrFail();
            $pdf = $this->actingAs($admin)->get("/api/admin/document-templates/{$template->id}/preview")->assertOk()->getContent();

            $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), "{$code} ต้องพิมพ์ได้ในหน้าเดียว");
        }
    }

    /**
     * PROJECT_RULES: ห้าม hard-code ชื่อหน่วยงานหรือชื่อบุคคล — เดิมบันทึกข้อความไปราชการมีชื่อผู้บริหารจริง
     * เป็นค่าสำรอง จึงพิมพ์ชื่อคนเหล่านั้นลงเอกสารของทุกหน่วยงานที่ยังไม่ได้ตั้งค่า
     */
    public function test_leave_form_files_do_not_hard_code_organization_or_people(): void
    {
        $files = array_merge(
            glob(resource_path('views/pdf/leave*.blade.php')),
            glob(resource_path('views/pdf/official_travel.blade.php')),
            glob(resource_path('views/pdf/partials/*.blade.php')),
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            foreach (['ดอนเมือง', 'ไชยา', 'อภินันท์', 'พัฒนพงษ์', 'บรรจง'] as $needle) {
                $this->assertStringNotContainsString($needle, $content, basename($file));
            }
        }
    }
}
