<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\ApprovalStep;
use App\Models\Department;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;
    protected User $manager;
    protected Leave $leave;
    protected LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $empRole  = Role::create(['name' => 'Employee', 'guard_name' => 'web']);
        $mgmtRole = Role::create(['name' => 'Manager',  'guard_name' => 'web']);
        $dept     = Department::create(['name' => 'ทดสอบ']);

        // Approval step: Manager approves at step 1
        ApprovalStep::create(['role_id' => $mgmtRole->id, 'step_order' => 1]);

        $this->employee = User::factory()->create([
            'department_id' => $dept->id,
        ]);
        $this->employee->assignRole('Employee');

        $this->manager = User::factory()->create([
            'department_id' => $dept->id,
        ]);
        $this->manager->assignRole('Manager');

        $this->leaveType = LeaveType::create(['name' => 'ลาพักร้อน', 'max_days' => 10]);

        LeaveBalance::create([
            'user_id'       => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'year'          => now()->year,
            'quota'         => 10,
            'used'          => 0,
        ]);

        $this->leave = Leave::create([
            'user_id'        => $this->employee->id,
            'leave_type_id'  => $this->leaveType->id,
            'start_date'     => now()->addDays(5)->toDateString(),
            'end_date'       => now()->addDays(6)->toDateString(),
            'total_days'     => 2,
            'reason'         => 'ทดสอบ',
            'status'         => 'Pending',
            'approval_level' => 1,
        ]);
    }

    public function test_manager_can_approve_leave(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson("/api/approvals/{$this->leave->id}/approve", ['comment' => 'ผ่าน']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'id'     => $this->leave->id,
            'status' => 'Approved',
        ]);
    }

    public function test_approve_deducts_leave_balance(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/approvals/{$this->leave->id}/approve");

        $balance = LeaveBalance::where('user_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->first();

        $this->assertEquals(2, $balance->used);
    }

    public function test_manager_can_reject_leave(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson("/api/approvals/{$this->leave->id}/reject", ['comment' => 'ไม่ผ่าน']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leaves', [
            'id'     => $this->leave->id,
            'status' => 'Rejected',
        ]);
    }

    public function test_employee_cannot_approve_own_leave(): void
    {
        $response = $this->actingAs($this->employee)
            ->postJson("/api/approvals/{$this->leave->id}/approve");

        $response->assertStatus(403);
    }

    public function test_cannot_approve_already_approved_leave(): void
    {
        // Approve once
        $this->actingAs($this->manager)
            ->postJson("/api/approvals/{$this->leave->id}/approve");

        // Try to approve again
        $response = $this->actingAs($this->manager)
            ->postJson("/api/approvals/{$this->leave->id}/approve");

        $response->assertStatus(422);
    }
}
