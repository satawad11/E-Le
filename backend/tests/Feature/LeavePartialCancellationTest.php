<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\Attendance\LeaveAttendanceSync;
use App\Services\LeavePolicyService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * การยกเลิกลาบางช่วงต้องคืนวันลาให้ถูกต้องพอดี ไม่ขาดไม่เกิน
 *
 * ครอบข้อ 08 · 09 · 10 ของผลตรวจโมดูลลาและการลงเวลา
 */
class LeavePartialCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $officer;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\FiscalYearSeeder', '--force' => true]);

        $department = Department::create(['name' => 'ฝ่ายทดสอบยกเลิกบางช่วง']);
        $personnelType = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ข้าราชการ']);

        $this->type = LeaveType::create(['name' => 'ลากิจบางช่วง', 'code_prefix' => 'ลก.', 'max_days' => 30, 'attendance_bucket' => 'personal']);
        $this->policy = LeavePolicy::create([
            'personnel_type_id' => $personnelType->id,
            'leave_type_id' => $this->type->id,
            'effective_from' => now()->subYear()->startOfYear(),
            'annual_quota' => 20,
            'max_days_with_pay' => 3,
            'notice_period_days' => 0,
            'allow_past_dates' => true,
            'exclude_holidays' => true,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);

        $this->employee = User::factory()->create(['department_id' => $department->id, 'personnel_type_id' => $personnelType->id]);
        $this->officer = User::factory()->create(['department_id' => $department->id]);
        $this->officer->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));
    }

    /*
     * ข้อ 08 — เดิมตรวจแค่ว่าช่วงอยู่ในใบลา ยื่นสองคำขอครอบวันเดียวกันแล้วอนุมัติทั้งคู่
     * ระบบจะลบ `used` สองรอบ ผู้ใช้ได้วันลาคืนเกินจริง
     */
    public function test_a_second_request_over_the_same_days_is_refused(): void
    {
        $leave = $this->approvedLeave(5);

        $this->request($leave, 1, 2)->assertStatus(201);
        $this->request($leave, 2, 3)->assertStatus(422);

        $this->assertSame(1, LeaveAdjustment::where('leave_id', $leave->id)->count());
    }

    public function test_two_separate_ranges_are_allowed_but_cannot_add_up_to_the_whole_leave(): void
    {
        $leave = $this->approvedLeave(5);

        $this->request($leave, 0, 0)->assertStatus(201);
        $this->request($leave, 1, 1)->assertStatus(201);
        // สองคำขอแรกรวม 2 วัน คำขอที่สามอีก 3 วันจะครบทั้งใบ — ต้องใช้คำขอยกเลิกใบลาแทน
        $this->request($leave, 2, 4)->assertStatus(422);
    }

    /*
     * ข้อ 09 — เดิมนับวันโดยไม่ส่งตัวผู้ใช้ จึงใช้ปฏิทินระดับองค์กร ไม่ใช่ตารางของเจ้าตัว
     */
    public function test_the_day_count_skips_the_same_holidays_the_leave_did(): void
    {
        $friday = today()->next(CarbonInterface::FRIDAY);
        $leave = $this->approvedLeaveBetween($friday->toDateString(), $friday->copy()->addDays(4)->toDateString());

        // ศุกร์ + เสาร์ + อาทิตย์ + จันทร์ = 2 วันทำการ
        $response = $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/partial-cancellation", [
            'cancel_from' => $friday->toDateString(),
            'cancel_to' => $friday->copy()->addDays(3)->toDateString(),
            'reason' => 'ทดสอบวันหยุดคร่อม',
        ])->assertStatus(201);

        $this->assertSame(2.0, (float) $response->json('days'), 'ต้องนับเฉพาะวันทำการเหมือนตอนยื่นใบลา');
    }

    /*
     * ข้อ 10 — เดิมลดเฉพาะ total_days วันที่ได้รับ/ไม่ได้รับเงินเดือนจึงรวมกันไม่เท่าทั้งใบอีกต่อไป
     */
    public function test_approving_returns_the_days_and_keeps_the_paid_split_consistent(): void
    {
        $leave = $this->approvedLeave(5);

        $this->assertSame(3.0, (float) $leave->paid_days, 'เพดานวันได้รับเงินเดือนคือ 3 วัน');
        $this->assertSame(2.0, (float) $leave->unpaid_days);

        $balance = LeaveBalance::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame(5.0, (float) $balance->used);

        $adjustment = LeaveAdjustment::findOrFail($this->request($leave, 3, 4)->json('id'));

        $this->actingAs($this->officer)
            ->putJson("/api/leave-adjustments/{$adjustment->id}/decision", ['status' => 'Approved'])
            ->assertOk();

        $leave->refresh();

        $this->assertSame(3.0, (float) $leave->total_days);
        $this->assertSame(3.0, (float) $leave->paid_days);
        $this->assertSame(0.0, (float) $leave->unpaid_days, 'คืนวันที่ไม่ได้รับเงินเดือนก่อน');
        $this->assertSame(
            (float) $leave->total_days,
            (float) $leave->paid_days + (float) $leave->unpaid_days,
            'ผลรวมวันได้รับและไม่ได้รับเงินเดือนต้องเท่ากับจำนวนวันทั้งใบเสมอ',
        );
        $this->assertSame(3.0, (float) $balance->fresh()->used);
    }

    public function test_approving_clears_only_the_cancelled_days_from_the_attendance_sheet(): void
    {
        $leave = $this->approvedLeave(5);

        $this->assertSame(5, Attendance::where('user_id', $this->employee->id)->where('status', 'Leave')->count());

        $adjustment = LeaveAdjustment::findOrFail($this->request($leave, 3, 4)->json('id'));

        $this->actingAs($this->officer)
            ->putJson("/api/leave-adjustments/{$adjustment->id}/decision", ['status' => 'Approved'])
            ->assertOk();

        $this->assertSame(
            3,
            Attendance::where('user_id', $this->employee->id)->where('status', 'Leave')->count(),
            'เหลือเฉพาะวันที่ยังลาอยู่จริง',
        );
    }

    public function test_a_rejected_request_returns_nothing_and_frees_the_days_for_a_new_request(): void
    {
        $leave = $this->approvedLeave(5);
        $adjustment = LeaveAdjustment::findOrFail($this->request($leave, 1, 2)->json('id'));

        $this->actingAs($this->officer)
            ->putJson("/api/leave-adjustments/{$adjustment->id}/decision", ['status' => 'Rejected'])
            ->assertOk();

        $this->assertSame(5.0, (float) $leave->fresh()->total_days);
        $this->assertSame(5.0, (float) LeaveBalance::where('user_id', $this->employee->id)->value('used'));

        // คำขอที่ถูกปฏิเสธไม่ควรกันช่วงวันไว้อีกต่อไป
        $this->request($leave, 1, 2)->assertStatus(201);
    }

    /** ใบลาที่อนุมัติแล้วยาว n วันทำการติดกัน */
    private function approvedLeave(int $days): Leave
    {
        $start = $this->workday(1);
        $end = $this->workday($days);

        return $this->approvedLeaveBetween($start, $end);
    }

    private function approvedLeaveBetween(string $start, string $end): Leave
    {
        $today = today();
        $fy = FiscalYear::whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();

        LeaveBalance::firstOrCreate(
            [
                'user_id' => $this->employee->id,
                'leave_type_id' => $this->type->id,
                'year' => app(LeavePolicyService::class)->fiscalYearNumber($fy, $today),
            ],
            ['quota' => 20, 'used' => 0, 'reserved' => 0, 'carried_forward' => 0, 'leave_policy_id' => $this->policy->id],
        );

        $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'ทดสอบยกเลิกบางช่วง',
        ])->assertSuccessful();

        $leave = Leave::where('user_id', $this->employee->id)->latest('id')->firstOrFail();

        // อนุมัติตรง ๆ ผ่านบริการ เพื่อไม่ผูกกับสายอนุมัติในเทสต์นี้
        app(LeaveService::class)->deductBalance($leave);
        $leave->update(['status' => 'Approved']);
        app(LeaveAttendanceSync::class)->apply($leave->fresh()->load(['type', 'user', 'policy']));

        return $leave->fresh();
    }

    /** ยื่นคำขอยกเลิกช่วงวันทำการลำดับที่ n ถึง m ของใบลา (นับจาก 0) */
    private function request(Leave $leave, int $fromIndex, int $toIndex)
    {
        $days = $this->leaveWorkdays($leave);

        return $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/partial-cancellation", [
            'cancel_from' => $days[$fromIndex],
            'cancel_to' => $days[$toIndex],
            'reason' => 'ทดสอบ',
        ]);
    }

    /** @return array<int,string> */
    private function leaveWorkdays(Leave $leave): array
    {
        $days = [];
        $date = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();

        for (; $date->lte($end); $date->addDay()) {
            if (! $date->isWeekend()) {
                $days[] = $date->toDateString();
            }
        }

        return $days;
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
