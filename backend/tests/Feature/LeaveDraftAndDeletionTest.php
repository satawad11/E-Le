<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeavePolicyService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ร่างใบลา และการปิดรูการลบใบลาที่เข้าสู่กระบวนการแล้ว
 *
 * ใบลาที่ผ่านการพิจารณาแล้วต้องตรวจสอบย้อนหลังได้เสมอ จึงลบไม่ได้ — ต้องใช้
 * "ยกเลิกใบลา" หรือ "เพิกถอนเอกสาร" ที่เก็บประวัติและเหตุผลไว้แทน
 *
 * ร่างคือสิ่งที่ทำให้กฎนั้นอยู่ได้โดยไม่ทำให้ผู้ใช้ลำบาก — กรอกผิดก็ลบร่างทิ้งได้
 */
class LeaveDraftAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    private LeaveType $type;

    /**
     * วันที่ตรึงไว้สำหรับทั้งคลาส — ไม่ใช่วันที่รันเทสต์
     *
     * **ปัญหาจริงที่การตรึงนี้เกิดมาเพื่อกัน (พบ 2026-09-16)** — `workday()` นับจาก
     * "วันนี้" ไปข้างหน้าไม่กี่วัน · ปีงบประมาณขึ้นปีใหม่ทุก 1 ตุลาคม และระบบห้าม
     * ยื่นใบลาที่คร่อมปีงบประมาณ · ช่วงปลายเดือนกันยายนของทุกปี วันที่ที่เทสต์คำนวณ
     * จึงข้ามไปปีงบประมาณถัดไปเอง แล้วคำขอถูกปฏิเสธด้วย 422 — CI ล้มสองสัปดาห์ต่อปี
     * โดยไม่มีใครแก้อะไรผิด
     *
     * 15 มกราคม อยู่กลางปีงบประมาณพอดี ห่างจากทั้งวันขึ้นปีงบประมาณและวันขึ้นปีปฏิทิน
     * `FiscalYearSeeder` และตารางสิทธิ์วันลาใน `setUp()` อ่านจากนาฬิกาเดียวกันนี้
     * ทั้งหมดจึงยังสอดคล้องกันเหมือนเดิม
     */
    private const CLOCK = '2026-01-15 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::CLOCK);

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\FiscalYearSeeder', '--force' => true]);

        $department = OrgUnit::factory()->create(['name' => 'ฝ่ายทดสอบร่าง']);
        $personnelType = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ข้าราชการ']);

        $this->type = LeaveType::create(['name' => 'ลากิจร่าง', 'code_prefix' => 'ลก.', 'max_days' => 30]);
        LeavePolicy::create([
            'personnel_type_id' => $personnelType->id,
            'leave_type_id' => $this->type->id,
            'effective_from' => now()->subYear()->startOfYear(),
            'annual_quota' => 20,
            'notice_period_days' => 0,
            'allow_past_dates' => true,
            'exclude_holidays' => true,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'HR Head'], ['description' => 'หัวหน้าบุคลากร']);
        ApprovalStep::firstOrCreate(['step_order' => 1], ['role_id' => $role->id, 'name' => 'อนุมัติ']);

        $this->employee = User::factory()->create(['org_unit_id' => $department->id, 'personnel_type_id' => $personnelType->id]);
        $this->approver = User::factory()->create(['org_unit_id' => $department->id]);
        $this->approver->assignRole($role);
        $this->approver->givePermissionTo(Permission::findOrCreate('leave.approval.hr', 'web'));

        $today = today();
        $fy = FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'year' => app(LeavePolicyService::class)->fiscalYearNumber($fy, $today),
            'quota' => 20, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0,
        ]);
    }

    // ------------------------------------------------------------------ ร่าง

    /*
     * ร่างต้องไม่แตะสิ่งที่เป็นของทางราชการเลย
     *
     * โดยเฉพาะเลขที่หนังสือ ซึ่งเป็นลำดับที่เดินหน้าอย่างเดียว ถ้าออกให้ร่างแล้วร่างถูกลบ
     * ทะเบียนหนังสือจะมีเลขขาดเป็นช่วง ๆ ซึ่งอธิบายกับผู้ตรวจสอบไม่ได้
     */
    public function test_a_draft_takes_no_document_number_no_quota_and_no_approval_chain(): void
    {
        $draft = $this->saveDraft();

        $this->assertSame('Draft', $draft->status);
        $this->assertNull($draft->document_number, 'ร่างต้องไม่กินเลขที่หนังสือ');
        $this->assertNull($draft->submitted_at);
        $this->assertSame(0.0, (float) $draft->reserved_days, 'ร่างต้องไม่กันโควตา');
        $this->assertSame(0.0, (float) $this->balance()->reserved);
        $this->assertSame(0, LeaveApproval::where('leave_id', $draft->id)->count(), 'ร่างต้องยังไม่ผนึกสายอนุมัติ');
    }

    public function test_submitting_a_draft_issues_the_number_reserves_the_quota_and_seals_the_chain(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/submit")->assertOk();

        $draft->refresh();
        $this->assertSame('Pending', $draft->status);
        $this->assertNotNull($draft->document_number);
        $this->assertNotNull($draft->submitted_at);
        $this->assertSame(2.0, (float) $draft->reserved_days);
        $this->assertSame(2.0, (float) $this->balance()->reserved);
        $this->assertGreaterThan(0, LeaveApproval::where('leave_id', $draft->id)->count());
    }

    /** ร่างที่ถูกลบต้องไม่ทิ้งช่องว่างในทะเบียนเลขที่หนังสือ */
    public function test_deleting_a_draft_leaves_no_gap_in_the_document_numbering(): void
    {
        $draft = $this->saveDraft();
        $this->actingAs($this->employee)->deleteJson("/api/leaves/{$draft->id}")->assertOk();

        $submitted = $this->saveDraft($this->workday(10), $this->workday(11));
        $this->actingAs($this->employee)->postJson("/api/leaves/{$submitted->id}/submit")->assertOk();

        $this->assertStringEndsWith('-00001', $submitted->fresh()->document_number);
    }

    public function test_a_draft_is_private_until_it_is_submitted(): void
    {
        $draft = $this->saveDraft();

        $reviewer = User::factory()->create();
        $reviewer->assignRole(Role::findOrCreate('Super Admin', 'web'));

        $visible = collect($this->actingAs($reviewer)->getJson('/api/leaves')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertNotContains($draft->id, $visible, 'ร่างของคนอื่นต้องไม่โผล่แม้จะมีสิทธิ์ดูทั้งองค์กร');
    }

    public function test_a_draft_does_not_block_submitting_a_leave_over_the_same_dates(): void
    {
        $this->saveDraft();

        // ร่างยังไม่ใช่คำขอ จึงต้องไม่ไปกันช่วงเวลาของใบที่ยื่นจริง
        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $this->workday(3),
            'end_date' => $this->workday(4),
            'reason' => 'ยื่นจริง',
        ])->assertSuccessful();
    }

    /*
     * ร่างต้องแก้ได้จริง
     *
     * มิฉะนั้นกฎ "ลบได้เฉพาะร่าง" จะบังคับให้ผู้ใช้ลบแล้วกรอกใหม่ทั้งใบทุกครั้งที่พิมพ์ผิด
     * คำเดียว ซึ่งแย่กว่าไม่มีร่างเสียอีก
     */
    public function test_a_draft_can_be_edited_and_the_days_are_recalculated(): void
    {
        $draft = $this->saveDraft();
        $this->assertSame(2.0, (float) $draft->total_days);

        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/draft", [
            'leave_type_id' => $this->type->id,
            'start_date' => $this->workday(3),
            'end_date' => $this->workday(6),
            'reason' => 'แก้เหตุผลใหม่',
        ])->assertOk();

        $draft->refresh();
        $this->assertSame('แก้เหตุผลใหม่', $draft->reason);
        $this->assertSame(4.0, (float) $draft->total_days, 'เปลี่ยนช่วงวันแล้วจำนวนวันต้องคำนวณใหม่');
        $this->assertSame('Draft', $draft->status, 'แก้ร่างแล้วต้องยังเป็นร่างอยู่');
        $this->assertNull($draft->document_number, 'แก้ร่างต้องไม่ทำให้ออกเลขที่หนังสือ');
        $this->assertSame(0.0, (float) $this->balance()->reserved, 'แก้ร่างต้องไม่กันโควตา');
    }

    public function test_editing_a_draft_is_written_to_the_audit_trail(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/draft", [
            'leave_type_id' => $this->type->id,
            'start_date' => $this->workday(3),
            'end_date' => $this->workday(4),
            'reason' => 'แก้แล้ว',
        ])->assertOk();

        $this->assertDatabaseHas('leave_audit_logs', [
            'leave_id' => $draft->id,
            'user_id' => $this->employee->id,
            'action' => 'draft_updated',
        ]);
    }

    /** แก้ร่างแล้วต้องผ่านเกณฑ์เดียวกับตอนยื่น ไม่ใช่ผ่านตอนแก้แต่ไปตกตอนกดส่ง */
    public function test_editing_a_draft_is_checked_with_the_same_rules_as_submitting(): void
    {
        $draft = $this->saveDraft();

        // ช่วงวันที่เลือกไม่มีวันทำการเลย (เสาร์–อาทิตย์)
        $saturday = today()->next(CarbonInterface::SATURDAY);

        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/draft", [
            'leave_type_id' => $this->type->id,
            'start_date' => $saturday->toDateString(),
            'end_date' => $saturday->copy()->addDay()->toDateString(),
            'reason' => 'ลาเสาร์อาทิตย์',
        ])->assertStatus(422);

        $this->assertSame('ทดสอบร่าง', $draft->fresh()->reason, 'คำขอที่ไม่ผ่านต้องไม่แก้ร่างเดิม');
    }

    public function test_a_submitted_leave_can_no_longer_be_edited_as_a_draft(): void
    {
        $draft = $this->saveDraft();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/submit")->assertOk();

        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/draft", [
            'leave_type_id' => $this->type->id,
            'start_date' => $this->workday(3),
            'end_date' => $this->workday(4),
            'reason' => 'พยายามแก้หลังส่ง',
        ])->assertStatus(422);
    }

    public function test_someone_else_cannot_edit_your_draft(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->approver)->postJson("/api/leaves/{$draft->id}/draft", [
            'leave_type_id' => $this->type->id,
            'start_date' => $this->workday(3),
            'end_date' => $this->workday(4),
            'reason' => 'แก้ของคนอื่น',
        ])->assertForbidden();
    }

    /** เปิดร่างขึ้นมาแก้ต้องอ่านค่าที่บันทึกไว้กลับมาได้ */
    public function test_the_owner_can_open_their_draft_to_edit_it(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->employee)->getJson("/api/leaves/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('status', 'Draft')
            ->assertJsonPath('reason', 'ทดสอบร่าง');
    }

    public function test_only_the_owner_can_submit_their_draft(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->approver)->postJson("/api/leaves/{$draft->id}/submit")->assertForbidden();
    }

    // -------------------------------------------------------- ปิดรูการลบ

    /*
     * ใบที่ยื่นแล้วลบไม่ได้ — เดิมผู้มีสิทธิ์ `leave.management.manage` ลบได้ทุกสถานะ
     * และการลบไม่เขียน audit log เลยสักบรรทัด
     */
    public function test_a_submitted_leave_cannot_be_deleted_even_by_an_administrator(): void
    {
        $draft = $this->saveDraft();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/submit")->assertOk();

        $officer = User::factory()->create();
        $officer->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        $this->actingAs($officer)->deleteJson("/api/leaves/{$draft->id}")->assertStatus(422);
        $this->assertDatabaseHas('leaves', ['id' => $draft->id, 'deleted_at' => null]);
    }

    public function test_an_approved_leave_cannot_be_deleted(): void
    {
        $draft = $this->saveDraft();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$draft->id}/submit")->assertOk();
        $this->actingAs($this->approver)->postJson("/api/approvals/{$draft->id}/approve", ['comment' => 'อนุมัติ'])->assertOk();

        $officer = User::factory()->create();
        $officer->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        $this->actingAs($officer)->deleteJson("/api/leaves/{$draft->id}")->assertStatus(422);
        $this->assertSame('Approved', $draft->fresh()->status);
        $this->assertSame(2.0, (float) $this->balance()->used, 'โควตาที่ตัดไปต้องไม่ถูกคืนจากการพยายามลบ');
    }

    /** การลบร่างต้องเหลือร่องรอยว่าใครลบ เดิมการลบไม่เขียนประวัติเลย */
    public function test_deleting_a_draft_is_written_to_the_audit_trail(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->employee)->deleteJson("/api/leaves/{$draft->id}")->assertOk();

        $this->assertDatabaseHas('leave_audit_logs', [
            'leave_id' => $draft->id,
            'user_id' => $this->employee->id,
            'action' => 'draft_deleted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave.draft_deleted',
            'resource_id' => (string) $draft->id,
        ]);
    }

    public function test_someone_else_cannot_delete_your_draft(): void
    {
        $draft = $this->saveDraft();

        $this->actingAs($this->approver)->deleteJson("/api/leaves/{$draft->id}")->assertForbidden();
    }

    // ------------------------------------------------------------- helpers

    private function saveDraft(?string $start = null, ?string $end = null): Leave
    {
        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $start ?? $this->workday(3),
            'end_date' => $end ?? $this->workday(4),
            'reason' => 'ทดสอบร่าง',
            'save_as_draft' => true,
        ])->assertSuccessful();

        return Leave::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::where('user_id', $this->employee->id)->firstOrFail();
    }

    private function workday(int $offset): string
    {
        $date = today();
        for ($i = 0; $i < $offset; $i++) {
            $date = $date->addDay();
            while ($date->isWeekend()) {
                $date = $date->addDay();
            }
        }

        return $date->toDateString();
    }
}
