<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CLEANUP-01 (D4) — สายอนุมัติใบลาเดินด้วย Workflow Engine ได้ผลเท่าตรรกะเดิม
 *
 * เฟส LEGACY-05 เตรียมสวิตช์ `workflow.engine_is_source_of_truth` ไว้แต่ยังไม่มี
 * โค้ดส่วนใดอ่านค่านี้เลย การ cutover จึงยังทำไม่ได้จริง
 *
 * ตอนนี้ `LeaveApprovalCoordinator` อ่านสวิตช์นั้นแล้ว และการเขียนข้อมูลทั้งหมด
 * (`leave_approvals`, `leaves.approval_level`, `leaves.status`, สิทธิ์วันลา, audit log)
 * ยังเหมือนเดิมทุกกรณี — dual-write ตามข้อกำหนดข้อ 3 ของ 05-4
 *
 * เทสต์ชุดนี้รันสถานการณ์เดียวกัน **สองรอบ** คือปิดและเปิดสวิตช์ แล้วยืนยันว่า
 * ผลลัพธ์ที่ผู้ใช้และเอกสารมองเห็นตรงกันทุกจุด — เป็นหลักฐาน parity ที่เฟสกำหนดไว้
 *
 * หมายเหตุสำคัญ: สวิตช์ยังเป็น `false` โดยค่าเริ่มต้น การเปิดใช้จริงบนระบบ production
 * ต้องมีหลักฐาน shadow จากการอนุมัติจริงตาม `docs/checkpoints/LEGACY-05-REPORT.md` ข้อ 11
 */
class LeaveApprovalCutoverTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $firstApprover;

    private User $secondApprover;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['name' => 'ทดสอบ']);
        Role::create(['name' => 'Employee', 'guard_name' => 'web']);
        $head = Role::create(['name' => 'Department Head', 'guard_name' => 'web']);
        $director = Role::create(['name' => 'Director', 'guard_name' => 'web']);

        ApprovalStep::create(['name' => 'หัวหน้าแผนก', 'role_id' => $head->id, 'step_order' => 1]);
        ApprovalStep::create(['name' => 'ผู้อำนวยการ', 'role_id' => $director->id, 'step_order' => 2]);

        $this->employee = User::factory()->create(['department_id' => $department->id]);
        $this->employee->assignRole('Employee');

        $this->firstApprover = User::factory()->create(['department_id' => $department->id]);
        $this->firstApprover->assignRole('Department Head');

        $this->secondApprover = User::factory()->create(['department_id' => $department->id]);
        $this->secondApprover->assignRole('Director');

        $this->leaveType = LeaveType::create(['name' => 'ลาพักผ่อน', 'max_days' => 10]);

        app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();
    }

    /** @return array<string, array{bool}> */
    public static function sourceOfTruthProvider(): array
    {
        return [
            'ตรรกะเดิมเป็นผู้ตัดสิน' => [false],
            'workflow engine เป็นผู้ตัดสิน' => [true],
        ];
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_a_two_step_chain_ends_approved_and_deducts_the_balance(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->firstApprover)
            ->postJson("/api/approvals/{$leave->id}/approve", ['comment' => 'เห็นควรอนุญาต'])
            ->assertOk();

        $leave->refresh();
        $this->assertSame('Pending', $leave->status, 'ยังไม่จบสาย จึงต้องยังเป็นรออนุมัติ');
        $this->assertSame(2, $leave->approval_level, 'ต้องเดินไปขั้นที่สอง');
        $this->assertSame('Approved', $this->step($leave, 1)->status);
        $this->assertSame($this->firstApprover->id, $this->step($leave, 1)->approver_id);

        $this->actingAs($this->secondApprover)
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertOk();

        $leave->refresh();
        $this->assertSame('Approved', $leave->status);
        $this->assertSame('Approved', $this->step($leave, 2)->status);
        $this->assertSame(2.0, (float) $this->balance()->used, 'ต้องตัดสิทธิ์วันลาเมื่อจบสาย');
        $this->assertDatabaseHas('leave_audit_logs', ['leave_id' => $leave->id, 'action' => 'approval_approved']);
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_rejecting_at_the_first_step_ends_the_leave(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->firstApprover)
            ->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'ไม่อนุญาต'])
            ->assertOk();

        $leave->refresh();
        $this->assertSame('Rejected', $leave->status);
        $this->assertSame('Rejected', $this->step($leave, 1)->status);
        $this->assertSame('ไม่อนุญาต', $this->step($leave, 1)->comment);
        $this->assertSame(0.0, (float) $this->balance()->used);
        $this->assertDatabaseHas('leave_audit_logs', ['leave_id' => $leave->id, 'action' => 'approval_rejected']);
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_an_approver_from_a_later_step_cannot_jump_the_queue(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->secondApprover)
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertForbidden();

        $leave->refresh();
        $this->assertSame(1, $leave->approval_level);
        $this->assertSame('Pending', $this->step($leave, 1)->status);
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_the_owner_can_never_approve_their_own_leave(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->employee)
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertForbidden();
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_a_finished_leave_cannot_be_approved_again(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->firstApprover)->postJson("/api/approvals/{$leave->id}/approve")->assertOk();
        $this->actingAs($this->secondApprover)->postJson("/api/approvals/{$leave->id}/approve")->assertOk();

        $this->actingAs($this->secondApprover)
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertStatus(422);
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_rejecting_without_a_reason_is_refused(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->firstApprover)
            ->postJson("/api/approvals/{$leave->id}/reject")
            ->assertStatus(422);
    }

    public function test_the_engine_path_leaves_a_real_instance_not_a_shadow_one(): void
    {
        config(['workflow.engine_is_source_of_truth' => true]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->firstApprover)->postJson("/api/approvals/{$leave->id}/approve")->assertOk();

        $instance = WorkflowInstance::where('subject_id', $leave->id)->firstOrFail();
        $this->assertFalse((bool) $instance->is_shadow, 'instance ที่ตัดสินจริงต้องไม่ถูกทำเครื่องหมายว่าเป็นการทดลอง');
        $this->assertSame(WorkflowInstance::STATUS_RUNNING, $instance->status);
    }

    public function test_the_legacy_path_still_records_a_shadow_comparison(): void
    {
        config(['workflow.engine_is_source_of_truth' => false, 'workflow.shadow_mode' => true]);
        $leave = $this->pendingLeave();

        $this->actingAs($this->firstApprover)->postJson("/api/approvals/{$leave->id}/approve")->assertOk();

        $this->assertDatabaseCount('workflow_shadow_comparisons', 1);
    }

    private function pendingLeave(): Leave
    {
        LeaveBalance::updateOrCreate(
            ['user_id' => $this->employee->id, 'leave_type_id' => $this->leaveType->id, 'year' => now()->year],
            ['quota' => 10, 'used' => 0],
        );

        $leave = Leave::create([
            'user_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบการอนุมัติ',
            'status' => 'Pending',
            'approval_level' => 1,
        ]);

        // ใบลาจริงได้แถวสรุปขั้นอนุมัติครบทุกขั้นตั้งแต่ตอนยื่น (ดู LeaveService::create)
        foreach (ApprovalStep::stepsFor(ApprovalStep::CHAIN_LEAVE) as $step) {
            LeaveApproval::create([
                'leave_id' => $leave->id,
                'stage' => LeaveApproval::STAGE_REQUEST,
                'step_order' => $step->step_order,
                'step_name' => $step->name,
                'required_role_id' => $step->role_id,
                'status' => 'Pending',
                'approver_type' => $step->approver_type ?: ApprovalStep::RESOLVER_ROLE,
                'specific_user_id' => $step->specific_user_id,
                'permission_name' => $step->permission_name,
            ]);
        }

        return $leave;
    }

    private function step(Leave $leave, int $order): LeaveApproval
    {
        return LeaveApproval::where('leave_id', $leave->id)->where('step_order', $order)->firstOrFail();
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::where('user_id', $this->employee->id)
            ->where('leave_type_id', $this->leaveType->id)
            ->firstOrFail();
    }
}
