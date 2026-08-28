<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Services\Workflow\LeaveCancellationWorkflowProvisioner;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CLEANUP-02 (D4) — สาย "ขอยกเลิกใบลา" เดินด้วย Workflow Engine ได้ผลเท่าตรรกะเดิม
 *
 * `docs/checkpoints/LEGACY-05-REPORT.md` ระบุว่าสายนี้ยังใช้ `approval_level` +
 * `ApprovalStep` ตรง ๆ และ **ยังไม่ถูกครอบด้วย shadow** จึงเป็น "สายที่สองที่ต้อง
 * รวมเข้า engine ก่อน cutover" — เทสต์ชุดนี้เป็นหลักฐานว่ารวมแล้วและผลตรงกัน
 *
 * รันสถานการณ์เดียวกันสองรอบ คือปิดและเปิด `workflow.engine_is_source_of_truth`
 */
class LeaveCancellationCutoverTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool}> */
    public static function sourceOfTruthProvider(): array
    {
        return [
            'ตรรกะเดิมเป็นผู้ตัดสิน' => [false],
            'workflow engine เป็นผู้ตัดสิน' => [true],
        ];
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_a_cancellation_walks_every_step_then_returns_the_leave_days(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        [$requester, $approverOne, $approverTwo, $leaveId] = $this->pendingCancellation();

        $this->actingAs($approverOne)
            ->postJson("/api/leaves/{$leaveId}/approve-cancellation", ['comment' => 'เห็นควรยกเลิก'])
            ->assertOk();

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveId, 'status' => 'Approved',
            'cancellation_status' => 'Pending', 'cancellation_approval_level' => 2,
        ]);

        // ผู้อนุมัติขั้นแรกกดซ้ำไม่ได้ เพราะคำขอเดินไปขั้นที่สองแล้ว
        $this->actingAs($approverOne)->postJson("/api/leaves/{$leaveId}/approve-cancellation")->assertForbidden();

        $this->actingAs($approverTwo)->postJson("/api/leaves/{$leaveId}/approve-cancellation")->assertOk();

        $this->assertDatabaseHas('leaves', ['id' => $leaveId, 'status' => 'Cancelled', 'cancellation_status' => 'Approved']);
        $this->assertEquals(0.0, (float) LeaveBalance::where('user_id', $requester->id)->value('used'));

        $steps = LeaveApproval::where('leave_id', $leaveId)
            ->where('stage', LeaveApproval::STAGE_CANCELLATION)->orderBy('step_order')->get();

        $this->assertSame($approverOne->id, $steps[0]->approver_id);
        $this->assertSame('เห็นควรยกเลิก', $steps[0]->comment);
        $this->assertSame($approverTwo->id, $steps[1]->approver_id);
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_rejecting_a_cancellation_keeps_the_leave_approved(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        [$requester, $approverOne, , $leaveId] = $this->pendingCancellation();

        $this->actingAs($approverOne)
            ->postJson("/api/leaves/{$leaveId}/reject-cancellation", ['comment' => 'ยังต้องใช้วันลานี้'])
            ->assertOk();

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveId, 'status' => 'Approved', 'cancellation_status' => 'Rejected',
        ]);
        // วันลายังถูกใช้อยู่ ไม่ถูกคืน
        $this->assertEquals(1.0, (float) LeaveBalance::where('user_id', $requester->id)->value('used'));
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_only_the_approver_of_the_current_step_may_act(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        [, , $approverTwo, $leaveId] = $this->pendingCancellation();

        $this->actingAs($approverTwo)
            ->postJson("/api/leaves/{$leaveId}/approve-cancellation")
            ->assertForbidden();

        $this->assertDatabaseHas('leaves', ['id' => $leaveId, 'cancellation_approval_level' => 1]);
    }

    #[DataProvider('sourceOfTruthProvider')]
    public function test_the_owner_can_never_decide_their_own_cancellation(bool $engineDecides): void
    {
        config(['workflow.engine_is_source_of_truth' => $engineDecides]);
        [$requester, , , $leaveId] = $this->pendingCancellation();

        $this->actingAs($requester)
            ->postJson("/api/leaves/{$leaveId}/approve-cancellation")
            ->assertForbidden();
    }

    public function test_the_two_chains_keep_separate_workflow_instances(): void
    {
        config(['workflow.engine_is_source_of_truth' => true]);
        [, $approverOne, , $leaveId] = $this->pendingCancellation();

        $this->actingAs($approverOne)->postJson("/api/leaves/{$leaveId}/approve-cancellation")->assertOk();

        $codes = WorkflowInstance::where('subject_id', $leaveId)
            ->with('version.definition')
            ->get()
            ->map(fn (WorkflowInstance $instance) => $instance->version->definition->code)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [LeaveWorkflowProvisioner::DEFINITION_CODE, LeaveCancellationWorkflowProvisioner::DEFINITION_CODE],
            $codes,
            'ใบลาหนึ่งใบต้องมีสองสายแยกกัน ไม่ใช่สายเดียวที่ถูกเดินซ้ำ',
        );
    }

    /**
     * ใบลาที่อนุมัติครบแล้วและมีคำขอยกเลิกรออนุมัติอยู่ที่ขั้นที่ 1
     *
     * @return array{0: User, 1: User, 2: User, 3: int}
     */
    private function pendingCancellation(): array
    {
        $first = Role::firstOrCreate(['name' => 'ผู้อนุมัติขั้นที่หนึ่ง', 'guard_name' => 'web']);
        $second = Role::firstOrCreate(['name' => 'ผู้อนุมัติขั้นที่สอง', 'guard_name' => 'web']);

        ApprovalStep::create(['name' => 'หัวหน้างานกลั่นกรอง', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $first->id]);
        ApprovalStep::create(['name' => 'ผู้อำนวยการอนุมัติ', 'step_order' => 2, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $second->id]);

        $approverOne = User::factory()->create(['is_active' => true]);
        $approverOne->assignRole($first);
        $approverTwo = User::factory()->create(['is_active' => true]);
        $approverTwo->assignRole($second);

        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบยกเลิก', 'code_prefix' => 'C']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10, 'period_basis' => 'fiscal_year',
            'exclude_holidays' => true, 'allow_half_day' => true, 'is_active' => true,
        ]);

        $requester = User::factory()->create(['is_active' => true, 'personnel_type_id' => $personnel->id]);

        $day = now()->addWeek()->nextWeekday()->toDateString();
        $leave = $this->actingAs($requester)->postJson('/api/leaves', [
            'leave_type_id' => $type->id, 'start_date' => $day, 'end_date' => $day, 'reason' => 'ทดสอบยกเลิก',
        ])->assertCreated()->json('leave');

        $this->actingAs($approverOne)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();
        $this->actingAs($approverTwo)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();

        $this->actingAs($requester)->postJson("/api/leaves/{$leave['id']}/cancel", [
            'cancellation_reason' => 'ภารกิจเปลี่ยน ขอยกเลิกวันลา',
        ])->assertOk();

        return [$requester, $approverOne, $approverTwo, $leave['id']];
    }
}
