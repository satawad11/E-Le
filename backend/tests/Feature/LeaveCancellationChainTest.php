<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * คำขอยกเลิกใบลาต้องเดินสายอนุมัติเหมือนการขอลา
 *
 * เดิมคำขอยกเลิกยืม `leaves.approval_level` ของใบลาไปใช้ ทับประวัติของสายเดิมทิ้ง
 * ไม่มี snapshot ของตัวเอง และไม่บันทึกว่าใครอนุมัติขั้นไหน
 */
class LeaveCancellationChainTest extends TestCase
{
    use RefreshDatabase;

    private array $roles = [];

    /** สายอนุมัติสองขั้นแบบ ROLE พร้อมผู้อนุมัติของแต่ละขั้น */
    private function twoStepChain(): array
    {
        $first = Role::firstOrCreate(['name' => 'ผู้อนุมัติขั้นที่หนึ่ง', 'guard_name' => 'web']);
        $second = Role::firstOrCreate(['name' => 'ผู้อนุมัติขั้นที่สอง', 'guard_name' => 'web']);

        ApprovalStep::create(['name' => 'หัวหน้างานกลั่นกรอง', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $first->id]);
        ApprovalStep::create(['name' => 'ผู้อำนวยการอนุมัติ', 'step_order' => 2, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $second->id]);

        $approverOne = User::factory()->create(['is_active' => true]);
        $approverOne->assignRole($first);
        $approverTwo = User::factory()->create(['is_active' => true]);
        $approverTwo->assignRole($second);

        return [$approverOne, $approverTwo];
    }

    /** @return array{0: User, 1: LeaveType} */
    private function requesterWithPolicy(): array
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบยกเลิก', 'code_prefix' => 'C']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10, 'period_basis' => 'fiscal_year',
            'exclude_holidays' => true, 'allow_half_day' => true, 'is_active' => true,
        ]);
        $dept = Department::create(['name' => 'ทดสอบยกเลิกใบลา']);
        $user = User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id, 'is_active' => true]);

        return [$user, $type];
    }

    /** ยื่นใบลาแล้วอนุมัติจนครบสาย คืนใบลาที่สถานะ Approved */
    private function approvedLeave(User $requester, LeaveType $type, User $approverOne, User $approverTwo): array
    {
        $day = now()->addWeek()->nextWeekday()->toDateString();
        $leave = $this->actingAs($requester)->postJson('/api/leaves', [
            'leave_type_id' => $type->id, 'start_date' => $day, 'end_date' => $day, 'reason' => 'ทดสอบยกเลิก',
        ])->assertCreated()->json('leave');

        $this->actingAs($approverOne)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();
        $this->actingAs($approverTwo)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Approved']);

        return $leave;
    }

    private function requestCancellation(User $requester, int $leaveId): void
    {
        $this->actingAs($requester)->postJson("/api/leaves/{$leaveId}/cancel", [
            'cancellation_reason' => 'ภารกิจเปลี่ยน ขอยกเลิกวันลา',
        ])->assertOk();
    }

    public function test_cancellation_walks_every_step_before_the_leave_is_cancelled(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);

        // ขั้นแรกอนุมัติแล้วยังไม่จบ ใบลายังเป็น Approved
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/approve-cancellation", ['comment' => 'เห็นควรยกเลิก'])->assertOk();
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Approved', 'cancellation_status' => 'Pending', 'cancellation_approval_level' => 2]);

        // ผู้อนุมัติขั้นแรกกดซ้ำไม่ได้ เพราะคำขอเดินไปขั้นที่สองแล้ว
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();

        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertOk();
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Cancelled', 'cancellation_status' => 'Approved']);

        // วันลาถูกคืนกลับเข้าสิทธิ์
        $this->assertEquals(0.0, (float) LeaveBalance::where('user_id', $requester->id)->value('used'));
    }

    public function test_each_cancellation_step_records_who_decided_it(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/approve-cancellation", ['comment' => 'เห็นควรยกเลิก'])->assertOk();
        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leave['id']}/approve-cancellation", ['comment' => 'อนุมัติ'])->assertOk();

        $steps = LeaveApproval::where('leave_id', $leave['id'])
            ->where('stage', LeaveApproval::STAGE_CANCELLATION)->orderBy('step_order')->get();

        $this->assertCount(2, $steps);
        $this->assertSame($approverOne->id, $steps[0]->approver_id);
        $this->assertSame('เห็นควรยกเลิก', $steps[0]->comment);
        $this->assertSame($approverTwo->id, $steps[1]->approver_id);
        $this->assertNotNull($steps[1]->acted_at);
    }

    public function test_the_cancellation_chain_does_not_disturb_the_leave_request_chain(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $levelAfterApproval = (int) Leave::findOrFail($leave['id'])->approval_level;

        $this->requestCancellation($requester, $leave['id']);

        // ระดับของสายขอลาต้องไม่ถูกคำขอยกเลิกเขียนทับ
        $this->assertSame($levelAfterApproval, (int) Leave::findOrFail($leave['id'])->approval_level);

        // ขั้นตอนของสายขอลายังคงสถานะ Approved ครบทุกขั้น
        $requestSteps = LeaveApproval::where('leave_id', $leave['id'])
            ->where('stage', LeaveApproval::STAGE_REQUEST)->get();
        $this->assertCount(2, $requestSteps);
        $this->assertTrue($requestSteps->every(fn ($step) => $step->status === 'Approved'));
    }

    public function test_changing_the_chain_after_a_cancellation_request_does_not_move_it(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);

        // ผู้ดูแลเปลี่ยนสายอนุมัติหลังยื่นคำขอยกเลิกไปแล้ว
        $newApprover = User::factory()->create(['is_active' => true]);
        ApprovalStep::where('step_order', 1)->update([
            'approver_type' => ApprovalStep::RESOLVER_SPECIFIC_USER,
            'specific_user_id' => $newApprover->id,
            'role_id' => null,
        ]);

        $this->actingAs($newApprover)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertOk();
    }

    public function test_rejecting_a_cancellation_keeps_the_leave_approved(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/reject-cancellation", ['comment' => 'ยังจำเป็นต้องใช้วันลานี้'])->assertOk();

        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Approved', 'cancellation_status' => 'Rejected']);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_id' => $leave['id'], 'stage' => LeaveApproval::STAGE_CANCELLATION,
            'step_order' => 1, 'status' => 'Rejected', 'approver_id' => $approverOne->id,
        ]);
        // วันลายังถูกใช้อยู่ ไม่ถูกคืน
        $this->assertGreaterThan(0, (float) LeaveBalance::where('user_id', $requester->id)->value('used'));
    }

    public function test_only_the_approver_of_the_current_cancellation_step_may_act(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);

        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();
        // ขั้นที่สองยังไม่ถึงคิว
        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();
        // เจ้าของใบลาอนุมัติคำขอของตนเองไม่ได้
        $this->actingAs($requester)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();

        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'cancellation_status' => 'Pending']);
    }

    public function test_the_worklist_shows_a_cancellation_to_the_step_it_is_waiting_on(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);

        $this->actingAs($approverOne)->getJson('/api/approvals/pending')
            ->assertOk()->assertJsonPath('0.id', $leave['id']);
        $this->actingAs($approverTwo)->getJson('/api/approvals/pending')->assertOk()->assertJsonCount(0);

        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertOk();

        // คิวย้ายไปขั้นที่สองแล้ว
        $this->actingAs($approverOne)->getJson('/api/approvals/pending')->assertOk()->assertJsonCount(0);
        $this->actingAs($approverTwo)->getJson('/api/approvals/pending')
            ->assertOk()->assertJsonPath('0.id', $leave['id']);
    }

    public function test_a_second_cancellation_request_starts_the_chain_over(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/reject-cancellation", ['comment' => 'ยังไม่อนุมัติ'])->assertOk();

        // ยื่นใหม่ได้ และต้องเริ่มที่ขั้นแรกอีกครั้ง
        $this->requestCancellation($requester, $leave['id']);
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'cancellation_status' => 'Pending', 'cancellation_approval_level' => 1]);
        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertOk();
    }

    public function test_a_pending_cancellation_request_cannot_be_submitted_twice(): void
    {
        [$approverOne, $approverTwo] = $this->twoStepChain();
        [$requester, $type] = $this->requesterWithPolicy();
        $leave = $this->approvedLeave($requester, $type, $approverOne, $approverTwo);

        $this->requestCancellation($requester, $leave['id']);
        $this->actingAs($requester)->postJson("/api/leaves/{$leave['id']}/cancel", [
            'cancellation_reason' => 'ขอยกเลิกซ้ำ',
        ])->assertStatus(422);
    }
}
