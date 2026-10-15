<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * คำขอยกเลิกใบลาที่อนุมัติแล้ว — เจ้าของใบลายื่นคำขอได้ และยื่นใหม่ได้เมื่อถูกปฏิเสธ
 */
class LeaveCancellationRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $approverRole = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);
        ApprovalStep::create(['name' => 'ผู้อำนวยการ', 'role_id' => $approverRole->id, 'step_order' => 1]);

        $department = Department::create(['name' => 'ฝ่ายทดสอบ']);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $this->owner = User::factory()->create(['department_id' => $department->id]);
        $this->owner->assignRole('Employee');

        $this->leave = Leave::create([
            'user_id' => $this->owner->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Approved',
            'cancellation_status' => 'None',
        ]);
    }

    public function test_owner_can_request_cancellation_of_approved_leave(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/leaves/{$this->leave->id}/cancel", ['cancellation_reason' => 'ติดภารกิจราชการ'])
            ->assertOk();

        $this->leave->refresh();
        $this->assertSame('Pending', $this->leave->cancellation_status);
        $this->assertSame('Approved', $this->leave->status);
        $this->assertSame('ติดภารกิจราชการ', $this->leave->cancellation_reason);
    }

    public function test_duplicate_request_does_not_overwrite_a_pending_one(): void
    {
        $this->leave->update([
            'cancellation_status' => 'Pending',
            'cancellation_reason' => 'เหตุผลเดิม',
        ]);

        $this->actingAs($this->owner)
            ->postJson("/api/leaves/{$this->leave->id}/cancel", ['cancellation_reason' => 'เหตุผลใหม่'])
            ->assertStatus(422);

        $this->assertSame('เหตุผลเดิม', $this->leave->fresh()->cancellation_reason);
    }

    public function test_owner_can_request_again_after_a_rejected_cancellation(): void
    {
        $this->leave->update(['cancellation_status' => 'Rejected']);

        $this->actingAs($this->owner)
            ->postJson("/api/leaves/{$this->leave->id}/cancel", ['cancellation_reason' => 'ยื่นใหม่พร้อมหลักฐาน'])
            ->assertOk();

        $this->assertSame('Pending', $this->leave->fresh()->cancellation_status);
    }

    public function test_reason_is_required(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/leaves/{$this->leave->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason');

        $this->assertSame('None', $this->leave->fresh()->cancellation_status);
    }
}
