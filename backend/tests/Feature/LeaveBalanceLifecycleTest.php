<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeavePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * วันลาคงเหลือต้องถูกต้องตลอดวงจรชีวิตของใบลา
 *
 * ยื่น → จอง (reserved) · อนุมัติ → ตัดจริง (used) · ยกเลิก/ปฏิเสธ/เพิกถอน → คืนครบ
 * ตัวเลขที่หน้าจอแสดงต้องเดินตามนี้ทุกก้าว
 */
class LeaveBalanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\FiscalYearSeeder', '--force' => true]);

        $department = Department::create(['name' => 'ฝ่ายทดสอบวันลา']);
        $personnelType = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ข้าราชการ']);
        $this->type = LeaveType::create(['name' => 'ลาพักผ่อนทดสอบ', 'max_days' => 10, 'code_prefix' => 'V']);

        $this->policy = LeavePolicy::create([
            'personnel_type_id' => $personnelType->id,
            'leave_type_id' => $this->type->id,
            'effective_from' => now()->subYear()->startOfYear(),
            'annual_quota' => 10,
            'notice_period_days' => 0,
            'allow_past_dates' => true,
            'exclude_holidays' => false,
            'allow_half_day' => true,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);

        $approverRole = Role::firstOrCreate(['name' => 'HR Head'], ['description' => 'หัวหน้าบุคลากร']);
        ApprovalStep::firstOrCreate(['step_order' => 1], ['role_id' => $approverRole->id, 'name' => 'อนุมัติ']);

        $this->employee = User::factory()->create(['department_id' => $department->id, 'personnel_type_id' => $personnelType->id]);
        $this->approver = User::factory()->create(['department_id' => $department->id]);
        $this->approver->assignRole($approverRole);
        $this->approver->givePermissionTo(Permission::findOrCreate('leave.approval.hr', 'web'));
    }

    /** ยอดคงเหลือที่ผู้ใช้เห็นบนหน้าจอ — สูตรเดียวกับ DashboardView/LeaveRequestView */
    private function remainingOnScreen(): float
    {
        $row = collect($this->actingAs($this->employee)->getJson('/api/leaves/balances')->assertOk()->json())
            ->firstWhere('leave_type_id', $this->type->id);

        $this->assertNotNull($row, 'หน้าจอต้องเห็นแถวโควตาของประเภทการลานี้');

        return (float) $row['quota'] + (float) ($row['carried_forward'] ?? 0) - (float) $row['used'] - (float) ($row['pending'] ?? 0);
    }

    private function submitLeave(string $start, string $end): Leave
    {
        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'ทดสอบวันลาคงเหลือ',
        ])->assertSuccessful();

        return Leave::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    public function test_the_screen_shows_the_seeded_quota_before_anything_happens(): void
    {
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'year' => $this->fiscalYearNumber(), 'quota' => 10, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0,
        ]);

        $this->assertSame(10.0, $this->remainingOnScreen());
    }

    public function test_submitting_reserves_days_and_approving_deducts_them_once(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));

        $this->assertSame(2.0, (float) $leave->total_days);
        $this->assertSame(8.0, $this->remainingOnScreen(), 'ยื่นแล้วต้องกันวันไว้ทันที');
        $this->assertSame(0.0, (float) $this->balance()->used, 'ยังไม่อนุมัติต้องยังไม่ตัดจริง');

        $this->approve($leave);

        $this->assertSame(8.0, $this->remainingOnScreen(), 'อนุมัติแล้วยอดคงเหลือต้องเท่าเดิม ไม่ถูกหักซ้ำ');
        $this->assertSame(2.0, (float) $this->balance()->used);
        $this->assertSame(0.0, (float) $this->balance()->reserved);
    }

    public function test_cancelling_a_pending_leave_returns_the_reserved_days(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->assertSame(8.0, $this->remainingOnScreen());

        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel")->assertOk();

        $this->assertSame(10.0, $this->remainingOnScreen(), 'ยกเลิกใบที่ยังรออนุมัติต้องคืนวันลาครบ');
        $this->assertSame(0.0, (float) $this->balance()->reserved);
    }

    public function test_rejecting_returns_the_reserved_days(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));

        $this->actingAs($this->approver)
            ->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'ไม่อนุมัติ'])
            ->assertOk();

        $this->assertSame(10.0, $this->remainingOnScreen(), 'ปฏิเสธแล้วต้องคืนวันลาครบ');
    }

    public function test_approving_a_cancellation_of_an_approved_leave_returns_the_used_days(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->approve($leave);
        $this->assertSame(8.0, $this->remainingOnScreen());

        $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดภารกิจ'])
            ->assertOk();

        $this->assertSame(8.0, $this->remainingOnScreen(), 'แค่ยื่นขอยกเลิกยังไม่คืนวัน ต้องรออนุมัติก่อน');

        $this->actingAs($this->approver)
            ->postJson("/api/leaves/{$leave->id}/approve-cancellation")
            ->assertOk();

        $this->assertSame('Cancelled', $leave->fresh()->status);
        $this->assertSame(10.0, $this->remainingOnScreen(), 'อนุมัติยกเลิกแล้วต้องคืนวันลาที่ตัดไปแล้วครบ');
        $this->assertSame(0.0, (float) $this->balance()->used);
    }

    public function test_rejecting_a_cancellation_keeps_the_days_deducted(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->approve($leave);

        $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดภารกิจ'])
            ->assertOk();
        $this->actingAs($this->approver)
            ->postJson("/api/leaves/{$leave->id}/reject-cancellation", ['comment' => 'ไม่อนุมัติให้ยกเลิก'])
            ->assertOk();

        $this->assertSame('Approved', $leave->fresh()->status);
        $this->assertSame(8.0, $this->remainingOnScreen(), 'ปฏิเสธคำขอยกเลิก วันลาต้องยังถูกตัดอยู่');
    }

    /*
     * ประเภทการลาที่นับตาม "ปีปฏิทิน" — ตัวเลือกที่หน้าตั้งค่านโยบายรองรับจริง
     *
     * แถวโควตาถูกคีย์ด้วย `periodYear()` ซึ่งคืนปีปฏิทินสำหรับนโยบายชนิดนี้
     * แต่หน้าจอเดิมหาแถวด้วยเลขปีงบประมาณเสมอ ระหว่าง 1 ต.ค. – 31 ธ.ค.
     * เลขสองตัวนี้ต่างกันหนึ่งปี ผู้ใช้จึงเห็นคนละแถวกับที่ระบบตัดจริง
     */
    public function test_a_calendar_year_leave_type_shows_the_row_the_system_actually_deducts_from(): void
    {
        $this->travelTo(today()->setDate((int) today()->year, 11, 15));

        // หลัง 1 ต.ค. ปีงบประมาณปัจจุบันคือรอบใหม่ (เลขคีย์ = ปี ค.ศ. ที่รอบนั้นสิ้นสุด)
        $calendarYear = (int) today()->year;
        FiscalYear::query()->update(['is_current' => false]);
        FiscalYear::where('year', $calendarYear + 543 + 1)->update(['is_current' => true]);

        $this->policy->update(['period_basis' => 'calendar_year']);

        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'year' => $calendarYear, 'quota' => 10, 'used' => 0, 'reserved' => 0,
            'carried_forward' => 0, 'leave_policy_id' => $this->policy->id,
        ]);

        $leave = $this->submitLeave($this->workday(3), $this->workday(4));

        $this->assertSame($calendarYear, (int) LeaveBalance::whereKey(
            LeaveBalance::where('user_id', $this->employee->id)->where('reserved', '>', 0)->value('id')
        )->value('year'), 'ระบบต้องกันวันไว้ในแถวปีปฏิทิน');

        $this->assertSame(8.0, $this->remainingOnScreen(), 'หน้าจอต้องอ่านแถวเดียวกับที่ระบบตัดจริง');

        $this->approve($leave);
        $this->assertSame(8.0, $this->remainingOnScreen());
    }

    /*
     * ประเภทการลาที่ระเบียบไม่จำกัดจำนวนวัน (annual_quota = null)
     *
     * แถวโควตาที่ถูกสร้างตอนติดตั้งไม่ได้ผูก `leave_policy_id` ไว้ หน้าจอจึงตัดสินไม่ได้ว่า
     * ไม่จำกัดโควตา และแสดงเป็น "เหลือ 0 วัน" ราวกับผู้ใช้ไม่มีสิทธิ์ลาประเภทนั้นเลย
     */
    public function test_an_unlimited_leave_type_is_not_shown_as_zero_days_left(): void
    {
        $this->policy->update(['annual_quota' => null]);

        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'year' => $this->fiscalYearNumber(), 'quota' => 0, 'used' => 0, 'reserved' => 0,
            'carried_forward' => 0, // จงใจไม่ผูก leave_policy_id เหมือนแถวที่ seeder สร้าง
        ]);

        $row = collect($this->actingAs($this->employee)->getJson('/api/leaves/balances')->assertOk()->json())
            ->firstWhere('leave_type_id', $this->type->id);

        $this->assertTrue($row['unlimited'], 'ประเภทการลาที่ไม่จำกัดวันต้องถูกทำเครื่องหมายว่าไม่จำกัด');
    }

    /*
     * เพิกถอนเอกสาร (void) — เจ้าหน้าที่ยกเลิกใบลาที่อนุมัติแล้วโดยคงประวัติไว้
     *
     * เส้นทางนี้ไม่เคยมีเทสต์ครอบ ทั้งที่คืนวันลาเหมือนกัน และเป็นเส้นทางที่ใช้จริง
     * ตอนเจ้าหน้าที่ต้องแก้เอกสารย้อนหลัง
     */
    public function test_voiding_an_approved_leave_returns_the_days(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->approve($leave);
        $this->assertSame(8.0, $this->remainingOnScreen());

        $officer = User::factory()->create();
        $officer->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        $this->actingAs($officer)
            ->postJson("/api/leaves/{$leave->id}/void", ['reason' => 'ออกเอกสารผิด'])
            ->assertOk();

        $this->assertSame(10.0, $this->remainingOnScreen(), 'เพิกถอนเอกสารแล้วต้องคืนวันลาครบ');
        $this->assertSame(0.0, (float) $this->balance()->used);
    }

    /*
     * แบบฟอร์มใบลาต้องพิมพ์ได้ตั้งแต่ยังรออนุมัติ
     *
     * ขั้นตอนราชการคือผู้ลาพิมพ์ใบลาออกมาเสนอผู้บังคับบัญชาลงนาม ใบที่ยังไม่อนุมัติ
     * จึงเป็นกรณีที่ *ต้อง* พิมพ์ได้ที่สุด — ห้ามพิมพ์เท่ากับตัดขั้นตอนการเสนอลงนามทิ้ง
     */
    public function test_a_pending_leave_can_be_printed_for_signatures(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));

        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf")->assertOk();
        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/word")->assertOk();
    }

    public function test_an_approved_leave_can_be_downloaded(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->approve($leave);

        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf")->assertOk();
    }

    /*
     * ใบที่อนุมัติแล้วภายหลังถูกยกเลิก ยังต้องพิมพ์ได้ เพราะเอกสารออกไปจริง
     * และต้องอยู่ในทะเบียนคุมการลา
     */
    public function test_a_cancelled_leave_that_was_once_approved_can_still_be_downloaded(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->approve($leave);

        $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดภารกิจ'])->assertOk();
        $this->actingAs($this->approver)
            ->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();

        $this->assertSame('Cancelled', $leave->fresh()->status);
        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf")->assertOk();
    }

    /*
     * ใบที่ผู้ยื่นถอนเองตั้งแต่ยังรออนุมัติ ไม่มี "ใบยกเลิกวันลา" ให้พิมพ์
     *
     * เพราะไม่เคยมีเอกสารที่อนุมัติแล้วให้ยกเลิก — ส่วนตัวใบลาเองยังพิมพ์ได้ตามปกติ
     */
    public function test_a_leave_withdrawn_while_pending_has_no_cancellation_form(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));

        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel")->assertOk();

        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf")->assertOk();
        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf?form=cancel")->assertStatus(422);
    }

    /** แบบฟอร์มใบยกเลิกต้องเคยมีคำขอยกเลิกก่อน */
    public function test_the_cancellation_form_needs_a_cancellation_request(): void
    {
        $this->seedBalance();
        $leave = $this->submitLeave($this->workday(3), $this->workday(4));
        $this->approve($leave);

        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf?form=cancel")->assertStatus(422);

        $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดภารกิจ'])->assertOk();

        $this->actingAs($this->employee)->get("/api/leaves/{$leave->id}/pdf?form=cancel")->assertOk();
    }

    private function fiscalYearNumber(): int
    {
        $today = today();
        $fy = FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();

        return app(LeavePolicyService::class)->fiscalYearNumber($fy, $today);
    }

    private function seedBalance(): LeaveBalance
    {
        return LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'year' => $this->fiscalYearNumber(), 'quota' => 10, 'used' => 0, 'reserved' => 0,
            'carried_forward' => 0, 'leave_policy_id' => $this->policy->id,
        ]);
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::where('user_id', $this->employee->id)
            ->where('leave_type_id', $this->type->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function approve(Leave $leave): void
    {
        $this->actingAs($this->approver)
            ->postJson("/api/approvals/{$leave->id}/approve", ['comment' => 'อนุมัติ'])
            ->assertOk();
    }

    /** วันทำการถัดไปนับจากวันนี้ (ข้ามเสาร์-อาทิตย์) เพื่อไม่ให้จำนวนวันแกว่งตามวันที่รันเทสต์ */
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
