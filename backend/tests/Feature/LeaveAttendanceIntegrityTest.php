<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Attendance;
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
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ใบลาต้องสอดคล้องกับใบลงเวลาเสมอ และประเภทที่ไม่จำกัดวันต้องอนุมัติได้
 *
 * ครอบข้อ 01 · 04 · 05 · 06 · 07 · 11 · 12 ของผลตรวจโมดูลลาและการลงเวลา
 */
class LeaveAttendanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    private PersonnelType $personnelType;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\FiscalYearSeeder', '--force' => true]);

        $this->department = Department::create(['name' => 'ฝ่ายทดสอบลงเวลา']);
        $this->personnelType = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ข้าราชการ']);

        $role = Role::firstOrCreate(['name' => 'HR Head'], ['description' => 'หัวหน้าบุคลากร']);
        ApprovalStep::firstOrCreate(['step_order' => 1], ['role_id' => $role->id, 'name' => 'อนุมัติ']);

        $this->employee = User::factory()->create([
            'department_id' => $this->department->id,
            'personnel_type_id' => $this->personnelType->id,
        ]);
        $this->approver = User::factory()->create(['department_id' => $this->department->id]);
        $this->approver->assignRole($role);
        $this->approver->givePermissionTo(Permission::findOrCreate('leave.approval.hr', 'web'));
        $this->employee->givePermissionTo(Permission::findOrCreate('attendance.checkin', 'web'));
    }

    /*
     * ข้อ 01 — ประเภทการลาที่ระเบียบไม่จำกัดจำนวนวัน ต้องอนุมัติผ่าน
     *
     * `deductBalance()` เดิมคิด available = quota(0) − used แล้วเทียบกับจำนวนวันที่ขอ
     * จึงคืน false ทุกครั้ง ผู้อนุมัติได้ 422 "วันลาคงเหลือไม่เพียงพอ" ใบลาค้าง Pending ตลอดไป
     */
    public function test_an_unlimited_leave_type_can_be_approved(): void
    {
        $type = $this->leaveType('ลาไปศึกษาต่อทดสอบ', 'ลศ.');
        $this->policy($type, annualQuota: null);

        $leave = $this->submit($type, 3, 7);

        $this->approve($leave)->assertOk();

        $this->assertSame('Approved', $leave->fresh()->status);
        $this->assertSame(5.0, (float) LeaveBalance::where('user_id', $this->employee->id)->value('used'));
    }

    public function test_a_limited_leave_type_is_still_refused_when_the_quota_runs_out(): void
    {
        $type = $this->leaveType('ลาพักผ่อนทดสอบ', 'ลพ.');
        $this->policy($type, annualQuota: 10);
        $balance = $this->balance($type, quota: 10);

        $leave = $this->submit($type, 3, 4);

        // มีคนแก้โควตาลงหลังยื่น — ตอนอนุมัติต้องไม่ปล่อยผ่าน
        $balance->update(['quota' => 1]);

        $this->approve($leave)->assertStatus(422);
        $this->assertSame('Pending', $leave->fresh()->status);
    }

    /*
     * ข้อ 04 — ยกเลิกใบลาแล้ววันลาต้องหายจากใบลงเวลาด้วย
     */
    public function test_cancelling_an_approved_leave_clears_the_attendance_sheet(): void
    {
        $type = $this->leaveType('ลากิจทดสอบ', 'ลก.');
        $this->policy($type, annualQuota: 10);
        $this->balance($type, quota: 10);

        $leave = $this->submit($type, 3, 4);
        $this->approve($leave)->assertOk();

        $this->assertSame(2, Attendance::where('user_id', $this->employee->id)->where('status', 'Leave')->count(),
            'อนุมัติแล้วต้องลงวันลาในใบลงเวลาให้เองโดยไม่ต้องรอกดปุ่มซิงค์');

        $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดภารกิจ'])->assertOk();
        $this->actingAs($this->approver)
            ->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();

        $this->assertSame(0, Attendance::where('user_id', $this->employee->id)->where('status', 'Leave')->count(),
            'ยกเลิกแล้ววันลาต้องหายจากใบลงเวลา ไม่ค้างไว้ให้สรุปรายเดือนนับต่อ');
    }

    public function test_clearing_keeps_a_day_that_has_a_real_clock_in(): void
    {
        $type = $this->leaveType('ลาป่วยทดสอบ', 'ลป.');
        $this->policy($type, annualQuota: 10);
        $this->balance($type, quota: 10);

        $leave = $this->submit($type, 3, 4);
        $this->approve($leave)->assertOk();

        $day = Attendance::where('user_id', $this->employee->id)->orderBy('date')->firstOrFail();
        $day->update(['check_in_time' => now(), 'status' => 'Present']);

        $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'กลับมาทำงาน'])->assertOk();
        $this->actingAs($this->approver)
            ->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();

        $this->assertDatabaseHas('attendances', ['id' => $day->id, 'status' => 'Present']);
        $this->assertStringNotContainsString('ลา:', Attendance::find($day->id)->notes ?? '');
    }

    /*
     * ข้อ 06 — วันหยุดที่คร่อมช่วงลาต้องไม่ขึ้นในใบลงเวลา
     */
    public function test_holidays_inside_the_leave_range_are_not_marked_as_leave(): void
    {
        $type = $this->leaveType('ลากิจคร่อมสุดสัปดาห์', 'ลก.');
        $this->policy($type, annualQuota: 20);
        $this->balance($type, quota: 20);

        // เริ่มวันศุกร์ จบวันจันทร์ — เสาร์อาทิตย์อยู่ตรงกลาง
        $friday = today()->next(CarbonInterface::FRIDAY);
        $monday = $friday->copy()->addDays(3);

        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $friday->toDateString(),
            'end_date' => $monday->toDateString(),
            'reason' => 'ทดสอบวันหยุดคร่อม',
        ])->assertSuccessful();

        $leave = Leave::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
        $this->assertSame(2.0, (float) $leave->total_days, 'ใบลานับเฉพาะวันทำการอยู่แล้ว');

        $this->approve($leave)->assertOk();

        $this->assertSame(2, Attendance::where('user_id', $this->employee->id)->where('status', 'Leave')->count(),
            'ใบลงเวลาต้องนับเท่าใบลา ไม่ใช่ 4 วันตามปฏิทิน');
    }

    /*
     * ข้อ 05 + 07 — สรุปรายเดือนแยกประเภทจากค่าที่กำหนด และลาครึ่งวันนับ 0.5
     */
    public function test_the_monthly_sheet_counts_each_leave_type_in_its_own_column(): void
    {
        $vacation = $this->leaveType('ลาพักผ่อน', 'ลพ.', 'vacation');
        $spouse = $this->leaveType('ลาไปช่วยเหลือภริยาที่คลอดบุตร', 'ลช.', 'personal');
        $this->policy($vacation, annualQuota: 10);
        $this->policy($spouse, annualQuota: 15);
        $this->balance($vacation, quota: 10);
        $this->balance($spouse, quota: 15);

        $first = $this->submit($vacation, 3, 3);
        $this->approve($first)->assertOk();
        $second = $this->submit($spouse, 8, 8);
        $this->approve($second)->assertOk();

        $month = Carbon::parse($first->start_date);
        $this->assertSame(
            $month->month,
            Carbon::parse($second->start_date)->month,
            'ทั้งสองใบต้องอยู่เดือนเดียวกันจึงเทียบยอดรวมในใบลงเวลาเดียวกันได้',
        );

        $row = $this->summaryRow($month);

        $this->assertSame(1.0, (float) $row['total_vacation'], 'ลาพักผ่อนต้องมีช่องของตัวเอง ไม่ถูกนับเป็นลากิจ');
        $this->assertSame(1.0, (float) $row['total_personal'], 'ลาไปช่วยเหลือภริยาที่คลอดบุตรไม่ใช่ลาคลอด');
        $this->assertSame(0.0, (float) $row['total_maternity']);
    }

    public function test_a_half_day_leave_counts_as_half_a_day_on_the_sheet(): void
    {
        $type = $this->leaveType('ลาป่วยครึ่งวัน', 'ลป.', 'sick');
        $this->policy($type, annualQuota: 10);
        $this->balance($type, quota: 10);

        $day = $this->workday(3);
        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $day,
            'end_date' => $day,
            'leave_period' => 'morning',
            'reason' => 'ลาครึ่งวัน',
        ])->assertSuccessful();

        $leave = Leave::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
        $this->assertSame(0.5, (float) $leave->total_days);
        $this->approve($leave)->assertOk();

        $row = $this->summaryRow(Carbon::parse($day));

        $this->assertSame(0.5, (float) $row['total_sick'], 'ยอดในใบลงเวลาต้องตรงกับจำนวนวันที่ตัดโควตาไป');
    }

    /*
     * ข้อ 11 — ลงเวลาออกต้องมีการลงเวลาเข้าก่อนเสมอ
     */
    public function test_checking_out_without_checking_in_is_refused(): void
    {
        $this->actingAs($this->employee)->postJson('/api/attendances/check-out')->assertStatus(422);

        $this->assertDatabaseCount('attendances', 0);
    }

    private function leaveType(string $name, string $prefix, ?string $bucket = null): LeaveType
    {
        return LeaveType::create([
            'name' => $name,
            'code_prefix' => $prefix,
            'max_days' => 30,
            'attendance_bucket' => $bucket,
        ]);
    }

    private function policy(LeaveType $type, ?float $annualQuota): LeavePolicy
    {
        return LeavePolicy::create([
            'personnel_type_id' => $this->personnelType->id,
            'leave_type_id' => $type->id,
            'effective_from' => now()->subYear()->startOfYear(),
            'annual_quota' => $annualQuota,
            'notice_period_days' => 0,
            'allow_past_dates' => true,
            'exclude_holidays' => true,
            'allow_half_day' => true,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);
    }

    private function balance(LeaveType $type, float $quota): LeaveBalance
    {
        $today = today();
        $fy = FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();

        return LeaveBalance::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'year' => app(LeavePolicyService::class)->fiscalYearNumber($fy, $today),
            'quota' => $quota, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0,
        ]);
    }

    private function submit(LeaveType $type, int $startOffset, int $endOffset): Leave
    {
        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $this->workday($startOffset),
            'end_date' => $this->workday($endOffset),
            'reason' => 'ทดสอบ',
        ])->assertSuccessful();

        return Leave::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    private function approve(Leave $leave)
    {
        return $this->actingAs($this->approver)
            ->postJson("/api/approvals/{$leave->id}/approve", ['comment' => 'อนุมัติ']);
    }

    /** แถวของบุคลากรคนนี้ในใบลงเวลารายเดือนที่ผู้ตรวจสอบเห็น */
    private function summaryRow(Carbon $month): array
    {
        $records = $this->actingAs($this->reviewer())
            ->getJson('/api/admin/attendances/summary?'.http_build_query([
                'year' => $month->year,
                'month' => $month->month,
                'user_id' => $this->employee->id,
            ]))
            ->assertOk()
            ->json();

        $row = collect($records)->firstWhere('user_id', $this->employee->id);
        $this->assertNotNull($row, 'ผู้ตรวจสอบต้องเห็นแถวของบุคลากรรายนี้ในใบลงเวลา');

        return $row;
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create(['department_id' => $this->department->id]);
        $reviewer->givePermissionTo(Permission::findOrCreate('attendance.view_all', 'web'));
        // ขอบเขตการมองเห็นระดับองค์กร — ผู้ตรวจสอบต้องเห็นบุคลากรทุกคนในใบลงเวลา
        $reviewer->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web'));

        return $reviewer;
    }

    /** วันทำการถัดไป เพื่อไม่ให้จำนวนวันแกว่งตามวันที่รันเทสต์ */
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
