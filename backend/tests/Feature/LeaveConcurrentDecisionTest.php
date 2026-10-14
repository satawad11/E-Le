<?php

namespace Tests\Feature;

use App\Http\Controllers\GovernmentApprovalController;
use App\Http\Controllers\LeaveCancellationController;
use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * กดอนุมัติ/ปฏิเสธ/ขอยกเลิกพร้อมกัน (ดับเบิลคลิก หรือผู้มีสิทธิ์ 2 คนในขั้นเดียวกัน)
 *
 * route model binding โหลดใบลามาก่อนเข้า transaction การตรวจสถานะนอก lock จึงเห็นค่าเก่า
 * จำลองด้วยการส่งโมเดลที่โหลดไว้ก่อนอีกคำขอหนึ่งทำรายการเสร็จ ให้ controller ตรงๆ
 */
class LeaveConcurrentDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    private User $secondApprover;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = Department::create(['name' => 'ฝ่ายทดสอบ']);
        $role = Role::create(['name' => 'Approver', 'guard_name' => 'web']);

        $this->type = LeaveType::create(['name' => 'ลาทดสอบพร้อมกัน', 'code_prefix' => 'C']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2020-01-01', 'period_basis' => 'fiscal_year', 'annual_quota' => 10,
            'exclude_holidays' => true, 'is_active' => true,
        ]);
        ApprovalStep::create(['name' => 'ผู้อนุมัติ', 'role_id' => $role->id, 'step_order' => 1]);

        $this->employee = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->approver = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->secondApprover = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->approver->assignRole($role);
        $this->secondApprover->assignRole($role);
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::where('user_id', $this->employee->id)->where('leave_type_id', $this->type->id)->firstOrFail();
    }

    private function submit(): Leave
    {
        $start = now()->addWeeks(2)->next(Carbon::MONDAY);
        $id = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'reason' => 'ทดสอบ',
        ])->assertCreated()->json('leave.id');

        return Leave::findOrFail($id);
    }

    private function request(User $user, array $input = []): Request
    {
        $request = Request::create('/', 'POST', $input);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('คำขอที่สองต้องถูกปฏิเสธ');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_second_final_approval_does_not_deduct_the_balance_twice(): void
    {
        $stale = $this->submit();
        $this->actingAs($this->approver)->postJson("/api/approvals/{$stale->id}/approve")->assertOk();
        $this->assertEquals(3.0, (float) $this->balance()->used);

        $this->assertRefused(fn () => app(GovernmentApprovalController::class)
            ->approve($this->request($this->secondApprover), $stale));

        $this->assertEquals(3.0, (float) $this->balance()->used);
        $this->assertSame($this->approver->id, $stale->approvals()->where('step_order', 1)->value('approver_id'));
    }

    public function test_rejecting_a_leave_that_was_just_approved_is_refused(): void
    {
        $stale = $this->submit();
        $this->actingAs($this->approver)->postJson("/api/approvals/{$stale->id}/approve")->assertOk();

        $this->assertRefused(fn () => app(GovernmentApprovalController::class)
            ->reject($this->request($this->secondApprover, ['comment' => 'ไม่อนุมัติ']), $stale));

        $this->assertSame('Approved', $stale->fresh()->status);
        $this->assertEquals(3.0, (float) $this->balance()->used);
    }

    public function test_duplicate_cancellation_request_submitted_concurrently_is_refused(): void
    {
        $leave = $this->submit();
        $this->actingAs($this->approver)->postJson("/api/approvals/{$leave->id}/approve")->assertOk();
        $stale = $leave->fresh();

        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดราชการ'])->assertOk();
        $response = app(LeaveCancellationController::class)
            ->cancel($this->request($this->employee, ['cancellation_reason' => 'ซ้ำ']), $stale);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('ติดราชการ', $leave->fresh()->cancellation_reason);
        $this->assertSame(1, LeaveActionLog::where('leave_id', $leave->id)->where('action', 'Cancellation Requested')->count());
    }
}
