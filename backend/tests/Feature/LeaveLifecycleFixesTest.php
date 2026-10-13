<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\ThaiGovernmentHoliday2570Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ครอบบั๊กที่พบจากการทดสอบวงจรการลาตั้งแต่ยื่นจนยกเลิก (2026-10-13)
 */
class LeaveLifecycleFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    private User $admin;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('leave.management.manage', 'web');
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = Department::create(['name' => 'ฝ่ายทดสอบ']);
        $approverRole = Role::create(['name' => 'Approver', 'guard_name' => 'web']);

        $this->type = LeaveType::create(['name' => 'ลาทดสอบวงจร', 'code_prefix' => 'T']);
        $this->policy = LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2020-01-01', 'period_basis' => 'fiscal_year', 'annual_quota' => 10,
            'exclude_holidays' => true, 'allow_half_day' => true, 'is_active' => true,
        ]);
        ApprovalStep::create(['name' => 'ผู้อนุมัติ', 'role_id' => $approverRole->id, 'step_order' => 1]);

        $this->employee = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->approver = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->approver->assignRole($approverRole);
        $this->admin = User::factory()->create(['personnel_type_id' => $personnel->id]);
        $this->admin->givePermissionTo('leave.management.manage');
    }

    /** อายุราชการอ่านจาก personnel.effective_from ก่อน user_profiles.start_date */
    private function serviceSince(User $user, Carbon $since): void
    {
        $user->profile()->updateOrCreate([], ['start_date' => $since->toDateString()]);
        $user->personnel()->updateOrCreate([], ['effective_from' => $since->toDateString()]);
    }

    private function nextMonday(): Carbon
    {
        return now()->addWeeks(2)->next(Carbon::MONDAY);
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::where('user_id', $this->employee->id)->where('leave_type_id', $this->type->id)->firstOrFail();
    }

    private function submit(array $overrides = [], ?User $as = null)
    {
        $start = $this->nextMonday();

        return $this->actingAs($as ?? $this->employee)->postJson('/api/leaves', array_merge([
            'leave_type_id' => $this->type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'reason' => 'ทดสอบ',
        ], $overrides));
    }

    private function approvedLeave(): Leave
    {
        $id = $this->submit()->assertCreated()->json('leave.id');
        $this->actingAs($this->approver)->postJson("/api/approvals/{$id}/approve")->assertOk();

        return Leave::findOrFail($id);
    }

    public function test_cancelling_an_approved_leave_returns_the_used_days(): void
    {
        $leave = $this->approvedLeave();
        $this->assertEquals(3.0, (float) $this->balance()->used);

        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดราชการ'])->assertOk();
        $this->actingAs($this->approver)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();

        $this->assertSame('Cancelled', $leave->fresh()->status);
        $this->assertEquals(0.0, (float) $this->balance()->used);
    }

    public function test_cancellation_approval_requires_a_pending_cancellation_request(): void
    {
        $id = $this->submit()->assertCreated()->json('leave.id');

        $this->actingAs($this->approver)->postJson("/api/leaves/{$id}/approve-cancellation")->assertStatus(422);

        $leave = Leave::findOrFail($id);
        $this->assertSame('Pending', $leave->status);
        $this->assertSame(1, (int) $leave->approval_level);
    }

    public function test_requester_and_unrelated_users_cannot_decide_a_cancellation(): void
    {
        $leave = $this->approvedLeave();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดราชการ'])->assertOk();

        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertForbidden();
        $this->actingAs($this->approver)->postJson("/api/leaves/{$leave->id}/reject-cancellation", ['comment' => 'ไม่อนุมัติ'])->assertOk();

        $this->assertSame('Approved', $leave->fresh()->status);
        $this->assertSame('Rejected', $leave->fresh()->cancellation_status);
        $this->assertEquals(3.0, (float) $this->balance()->used);
    }

    public function test_duplicate_cancellation_request_is_rejected(): void
    {
        $leave = $this->approvedLeave();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ครั้งแรก'])->assertOk();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ครั้งที่สอง'])->assertStatus(422);
    }

    public function test_half_day_leave_on_a_non_working_day_is_rejected(): void
    {
        $saturday = now()->addWeeks(2)->next(Carbon::SATURDAY)->toDateString();

        $this->submit(['start_date' => $saturday, 'end_date' => $saturday, 'leave_period' => 'morning'])->assertStatus(422);
        $this->assertSame(0, Leave::count());
    }

    public function test_morning_and_afternoon_on_the_same_day_do_not_overlap(): void
    {
        $day = $this->nextMonday()->toDateString();

        $this->submit(['start_date' => $day, 'end_date' => $day, 'leave_period' => 'morning'])->assertCreated();
        $this->submit(['start_date' => $day, 'end_date' => $day, 'leave_period' => 'afternoon'])->assertCreated();
        $this->submit(['start_date' => $day, 'end_date' => $day, 'leave_period' => 'morning'])->assertStatus(422);
        $this->submit(['start_date' => $day, 'end_date' => $day])->assertStatus(422);

        $this->assertEquals(1.0, (float) $this->balance()->reserved);
    }

    public function test_hr_recorded_leave_counts_as_used_and_closes_every_step(): void
    {
        // บันทึกย้อนหลัง — นโยบายห้ามลาย้อนหลังต้องไม่บล็อกฝ่ายบุคคล
        $this->serviceSince($this->employee, now()->subYears(5));
        $start = now()->subWeeks(2)->next(Carbon::MONDAY);
        $this->actingAs($this->admin)->postJson('/api/admin/leaves', [
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
            'reason' => 'บันทึกย้อนหลัง',
        ])->assertCreated();

        $leave = Leave::firstOrFail();
        $this->assertSame('Approved', $leave->status);
        $this->assertSame(0, $leave->approvals()->where('status', 'Pending')->count());
        $this->assertEquals(2.0, (float) $this->balance()->used);
        $this->assertEquals(0.0, (float) $this->balance()->reserved);
    }

    public function test_editing_dates_of_a_pending_leave_recalculates_days_and_reservation(): void
    {
        $id = $this->submit()->assertCreated()->json('leave.id');
        $start = $this->nextMonday();

        $this->actingAs($this->admin)->putJson("/api/leaves/{$id}", [
            'leave_type_id' => $this->type->id, 'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(4)->toDateString(), 'reason' => 'แก้ไข',
        ])->assertOk();

        $this->assertEquals(5.0, (float) Leave::findOrFail($id)->total_days);
        $this->assertEquals(5.0, (float) $this->balance()->reserved);
    }

    public function test_editing_dates_of_an_approved_leave_is_refused(): void
    {
        $leave = $this->approvedLeave();
        $start = $this->nextMonday();

        $this->actingAs($this->admin)->putJson("/api/leaves/{$leave->id}", [
            'leave_type_id' => $this->type->id, 'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(4)->toDateString(), 'reason' => 'แก้ไข',
        ])->assertStatus(422);

        $this->assertEquals(3.0, (float) $leave->fresh()->total_days);
    }

    public function test_resolver_based_step_is_pinned_and_actionable(): void
    {
        $designated = User::factory()->create();
        ApprovalStep::query()->update(['approver_type' => ApprovalStep::RESOLVER_SPECIFIC_USER, 'specific_user_id' => $designated->id, 'role_id' => null]);

        $id = $this->submit()->assertCreated()->json('leave.id');

        $pending = $this->actingAs($designated)->getJson('/api/approvals/pending')->assertOk()->json();
        $this->assertSame([$id], array_column($pending, 'id'));
        $this->assertSame([], $this->actingAs($this->approver)->getJson('/api/approvals/pending')->json());

        $this->actingAs($designated)->postJson("/api/approvals/{$id}/approve")->assertOk();
        $this->assertSame('Approved', Leave::findOrFail($id)->status);
    }

    public function test_balances_endpoint_initializes_the_current_fiscal_year(): void
    {
        $balances = $this->actingAs($this->employee)->getJson('/api/leaves/balances')->assertOk()->json();

        $row = collect($balances)->firstWhere('leave_type_id', $this->type->id);
        $this->assertNotNull($row);
        $this->assertEquals(10.0, (float) $row['quota']);
    }

    public function test_carry_forward_includes_users_without_personnel_type_and_applies_long_service_cap(): void
    {
        $this->policy->update([
            'is_carry_forward' => true, 'max_carry_forward' => 20, 'max_accumulated' => 20,
            'eligibility_rules' => ['long_service_years' => 10, 'max_accumulated_long_service' => 30],
        ]);
        $toYear = now()->month >= 10 ? now()->year + 1 : now()->year;

        $junior = User::factory()->create(['personnel_type_id' => null]);
        $this->serviceSince($junior, now()->subYears(3));
        $senior = User::factory()->create(['personnel_type_id' => null]);
        $this->serviceSince($senior, now()->subYears(15));
        foreach ([$junior, $senior] as $user) {
            LeaveBalance::create(['user_id' => $user->id, 'leave_type_id' => $this->type->id, 'year' => $toYear - 1, 'quota' => 10, 'carried_forward' => 20, 'used' => 0, 'reserved' => 0]);
        }

        $this->artisan('app:carry-forward-leaves')->assertSuccessful();

        $carried = fn (User $user) => (float) LeaveBalance::where('user_id', $user->id)->where('leave_type_id', $this->type->id)->where('year', $toYear)->value('carried_forward');
        $this->assertEquals(10.0, $carried($junior));  // สะสมรวมไม่เกิน 20 → ทบได้ 10
        $this->assertEquals(20.0, $carried($senior));  // รับราชการ ≥ 10 ปี สะสมรวมไม่เกิน 30 → ทบได้ 20
    }

    public function test_national_holidays_are_managed_by_calendar_managers_and_affect_leave_days(): void
    {
        Permission::findOrCreate('leave.calendar.manage', 'web');
        $manager = User::factory()->create();
        $manager->givePermissionTo('leave.calendar.manage');
        $monday = $this->nextMonday();

        $this->actingAs($this->employee)->postJson('/api/admin/holidays', ['date' => $monday->toDateString(), 'name' => 'วันหยุดพิเศษ'])->assertForbidden();
        $id = $this->actingAs($manager)->postJson('/api/admin/holidays', ['date' => $monday->toDateString(), 'name' => 'วันหยุดพิเศษ'])->assertCreated()->json('id');
        $this->actingAs($manager)->postJson('/api/admin/holidays', ['date' => $monday->toDateString(), 'name' => 'ซ้ำ'])->assertStatus(422);

        // จ.–พ. มีวันหยุด 1 วัน → เหลือ 2 วันทำการ
        $this->assertEquals(2.0, (float) $this->submit()->assertCreated()->json('leave.total_days'));

        $this->actingAs($manager)->deleteJson("/api/admin/holidays/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('holidays', ['id' => $id]);
    }

    public function test_2570_holidays_are_not_counted_as_working_days(): void
    {
        $this->seed(ThaiGovernmentHoliday2570Seeder::class);

        // สงกรานต์ 13–15 เม.ย. 2570 ตรงกับ อ.–พฤ. → 12–16 เม.ย. เหลือวันทำการ 2 วัน (จ. และ ศ.)
        $this->assertEquals(2.0, app(LeaveService::class)->calculateTotalDays('2027-04-12', '2027-04-16'));
        // ปีใหม่ 1 ม.ค. 2570 (ศ.) ไม่ใช่วันทำการ
        $this->assertEquals(0.0, app(LeaveService::class)->calculateTotalDays('2027-01-01', '2027-01-01'));
    }
}
