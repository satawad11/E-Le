<?php

namespace Tests\Legacy\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;

    protected LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Employee', 'guard_name' => 'web']);
        $dept = Department::create(['name' => 'ทดสอบ']);

        $this->employee = User::factory()->create([
            'department_id' => $dept->id,
        ]);
        $this->employee->assignRole('Employee');

        $this->leaveType = LeaveType::create([
            'name' => 'ลาป่วย',
            'max_days' => 30,
        ]);

        // Give balance
        LeaveBalance::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year' => (now()->month >= 10 ? now()->year + 1 : now()->year), // leave_balances.year = fiscal year key
            'quota' => 30,
            'used' => 0,
        ]);
    }

    /** ต้องเป็นวันทำการเสมอ ไม่งั้นเทสต์จะล้มเองเมื่อรันในวันที่ +5 วันตกเสาร์-อาทิตย์ */
    private function workdayRange(): array
    {
        $start = now()->addWeek()->nextWeekday();

        return [$start->toDateString(), $start->copy()->nextWeekday()->toDateString()];
    }

    public function test_employee_can_submit_leave_request(): void
    {
        [$start, $end] = $this->workdayRange();

        $response = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'ป่วย ไข้หวัด',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'ส่งคำขอลาเรียบร้อยแล้ว');

        $this->assertDatabaseHas('leaves', [
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'status' => 'Pending',
        ]);
    }

    public function test_leave_request_requires_valid_dates(): void
    {
        $response = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(), // end before start
            'reason' => 'ทดสอบ',
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_can_view_own_leaves(): void
    {
        $response = $this->actingAs($this->employee)->getJson('/api/leaves');
        $response->assertStatus(200)->assertJsonIsArray();
    }

    public function test_employee_can_view_balances(): void
    {
        $response = $this->actingAs($this->employee)->getJson('/api/leaves/balances');
        $response->assertStatus(200)->assertJsonIsArray();
        // ระบบสร้างยอดตั้งต้นของทุกประเภทลาที่มีนโยบายให้ด้วย — ยอดที่ตั้งไว้ต้องอยู่ครบ
        $row = collect($response->json())->firstWhere('leave_type_id', $this->leaveType->id);
        $this->assertNotNull($row);
        $this->assertEquals(30.0, (float) $row['quota']);
    }

    public function test_employee_can_cancel_pending_leave(): void
    {
        [$start, $end] = $this->workdayRange();

        // Create a leave
        $leaveResponse = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'ทดสอบ',
        ]);

        $leaveId = $leaveResponse->json('leave.id');
        $this->assertGreaterThan(0, LeaveBalance::where('user_id', $this->employee->id)->value('reserved'));

        $cancelResponse = $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leaveId}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJsonPath('message', 'ยกเลิกใบลาเรียบร้อยแล้ว');

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveId,
            'status' => 'Cancelled',
        ]);

        // ยกเลิกตอนรออนุมัติต้องคืนวันที่จองไว้ ไม่งั้นสิทธิ์จะหายถาวร
        $this->assertEquals(0.0, (float) LeaveBalance::where('user_id', $this->employee->id)->value('reserved'));
    }

    public function test_employee_cannot_cancel_approved_leave(): void
    {
        $leave = Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Approved',
        ]);

        $response = $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel");

        $response->assertStatus(422);
    }

    public function test_user_without_role_gets_forbidden_instead_of_server_error_when_deleting_leave(): void
    {
        $leave = Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'total_days' => 1,
            'reason' => 'ทดสอบสิทธิ์',
            'status' => 'Approved',
        ]);
        $userWithoutRole = User::factory()->create();

        $this->actingAs($userWithoutRole)
            ->deleteJson("/api/leaves/{$leave->id}")
            ->assertForbidden();
    }

    public function test_user_without_role_gets_forbidden_instead_of_server_error_when_updating_leave(): void
    {
        $leave = Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'total_days' => 1,
            'reason' => 'ทดสอบสิทธิ์',
            'status' => 'Pending',
        ]);
        $userWithoutRole = User::factory()->create();

        $this->actingAs($userWithoutRole)
            ->putJson("/api/leaves/{$leave->id}", [])
            ->assertForbidden();
    }
}
