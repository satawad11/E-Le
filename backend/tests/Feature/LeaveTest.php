<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\Department;

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
            'name'     => 'ลาป่วย',
            'max_days' => 30,
        ]);

        // Give balance
        LeaveBalance::create([
            'user_id'       => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year'          => now()->year,
            'quota'         => 30,
            'used'          => 0,
        ]);
    }

    public function test_employee_can_submit_leave_request(): void
    {
        $response = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date'    => now()->addDays(5)->toDateString(),
            'end_date'      => now()->addDays(6)->toDateString(),
            'reason'        => 'ป่วย ไข้หวัด',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'ส่งคำขอลาเรียบร้อยแล้ว');

        $this->assertDatabaseHas('leaves', [
            'user_id'       => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'status'        => 'Pending',
        ]);
    }

    public function test_leave_request_requires_valid_dates(): void
    {
        $response = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date'    => now()->addDays(5)->toDateString(),
            'end_date'      => now()->addDays(3)->toDateString(), // end before start
            'reason'        => 'ทดสอบ',
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
        $this->assertCount(1, $response->json());
    }

    public function test_employee_can_cancel_pending_leave(): void
    {
        // Create a leave
        $leaveResponse = $this->actingAs($this->employee)->postJson('/api/leaves', [
            'leave_type_id' => $this->leaveType->id,
            'start_date'    => now()->addDays(5)->toDateString(),
            'end_date'      => now()->addDays(6)->toDateString(),
            'reason'        => 'ทดสอบ',
        ]);

        $leaveId = $leaveResponse->json('leave.id');

        $cancelResponse = $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leaveId}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJsonPath('message', 'ยกเลิกใบลาเรียบร้อยแล้ว');

        $this->assertDatabaseHas('leaves', [
            'id'     => $leaveId,
            'status' => 'Cancelled',
        ]);
    }

    public function test_employee_cannot_cancel_approved_leave(): void
    {
        $leave = \App\Models\Leave::create([
            'user_id'       => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date'    => now()->addDays(1)->toDateString(),
            'end_date'      => now()->addDays(2)->toDateString(),
            'total_days'    => 2,
            'reason'        => 'ทดสอบ',
            'status'        => 'Approved',
        ]);

        $response = $this->actingAs($this->employee)
            ->postJson("/api/leaves/{$leave->id}/cancel");

        $response->assertStatus(422);
    }
}
