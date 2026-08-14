<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDelegation;
use App\Models\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowTask;
use App\Models\Workflow\WorkflowTransition;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use App\Services\Workflow\WorkflowAuthorizationException;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LEGACY-05 — พฤติกรรมของ Workflow Engine
 *
 * เทสต์ชุดนี้เป็นเงื่อนไขก่อน cutover ตามที่เฟสนี้กำหนด (Test-first)
 * ครอบ: sequential approval, reject, return, delegate, expired delegation,
 * version pinning, authorization, duplicate/concurrent action, audit completeness
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private Role $hr;

    private Role $deputy;

    private Role $director;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = Role::firstOrCreate(['name' => 'HR Head', 'guard_name' => 'web']);
        $this->deputy = Role::firstOrCreate(['name' => 'Deputy Director', 'guard_name' => 'web']);
        $this->director = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);

        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hr->id, 'name' => 'ตรวจสอบโดยหัวหน้าบุคลากร']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->deputy->id, 'name' => 'พิจารณาโดยรองผู้อำนวยการ']);
        ApprovalStep::create(['step_order' => 3, 'role_id' => $this->director->id, 'name' => 'อนุมัติรอบสุดท้าย']);

        $this->leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);
    }

    private function provisioner(): LeaveWorkflowProvisioner
    {
        return app(LeaveWorkflowProvisioner::class);
    }

    private function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->name);

        return $user->fresh();
    }

    private function makeLeave(?User $owner = null): Leave
    {
        $owner ??= User::factory()->create();

        return Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ workflow',
            'status' => 'Pending',
            'approval_level' => 1,
        ]);
    }

    private function startInstance(?User $owner = null): WorkflowInstance
    {
        $leave = $this->makeLeave($owner);
        $this->provisioner()->publishCurrentVersion();

        return $this->engine()->start($this->provisioner()->definition(), $leave, $leave->user_id);
    }

    // ------------------------------------------------------------- sequential

    public function test_a_leave_walks_every_step_in_order_before_completing(): void
    {
        $instance = $this->startInstance();

        $this->assertSame(1, (int) $this->engine()->currentStep($instance)->step_order);

        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->hr));
        $this->assertSame(2, (int) $this->engine()->currentStep($instance->refresh())->step_order);

        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->deputy));
        $this->assertSame(3, (int) $this->engine()->currentStep($instance->refresh())->step_order);

        $result = $this->engine()->perform($instance, 'approve', $this->userWithRole($this->director));

        $this->assertTrue($result['final']);
        $this->assertSame(WorkflowInstance::STATUS_COMPLETED, $instance->refresh()->status);
        $this->assertNull($instance->current_step_id);
    }

    public function test_an_approver_from_a_later_step_cannot_jump_the_queue(): void
    {
        $instance = $this->startInstance();

        $this->expectException(WorkflowAuthorizationException::class);
        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->director));
    }

    // ---------------------------------------------------------------- reject

    public function test_reject_ends_the_instance_immediately_at_any_step(): void
    {
        $instance = $this->startInstance();
        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->hr));

        $result = $this->engine()->perform($instance->refresh(), 'reject', $this->userWithRole($this->deputy), 'เอกสารไม่ครบ');

        $this->assertTrue($result['final']);
        $this->assertSame(WorkflowInstance::STATUS_REJECTED, $instance->refresh()->status);

        // ขั้นที่ยังไม่ถึงต้องถูกปิด ไม่ค้างเป็น pending
        $this->assertSame(0, $instance->tasks()->where('status', WorkflowTask::STATUS_PENDING)->count());
    }

    public function test_reject_requires_a_reason(): void
    {
        $instance = $this->startInstance();

        $this->expectException(WorkflowTransitionException::class);
        $this->engine()->perform($instance, 'reject', $this->userWithRole($this->hr));
    }

    // ---------------------------------------------------------------- return

    public function test_return_sends_the_request_back_to_the_previous_step(): void
    {
        $instance = $this->startInstance();
        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->hr));

        $result = $this->engine()->perform($instance->refresh(), 'return', $this->userWithRole($this->deputy), 'ขอข้อมูลเพิ่ม');

        $this->assertFalse($result['final']);
        $this->assertSame(1, (int) $result['next_step']->step_order);
        $this->assertSame(WorkflowInstance::STATUS_RUNNING, $instance->refresh()->status);

        /*
         * ขั้นที่ถูกส่งกลับต้องกลับมาเป็นขั้นปัจจุบันจริง ๆ
         *
         * เคยเป็นบั๊ก: ขั้นที่ 1 ถูกปิดไปแล้วตอนอนุมัติ พอส่งกลับ engine จึงไป
         * หยิบขั้นที่ยัง pending ตัวถัดไปซึ่งคือขั้นที่ 3 — กลายเป็นส่งกลับแล้ว
         * ข้ามไปข้างหน้าแทน
         */
        $this->assertSame(1, (int) $this->engine()->currentStep($instance->refresh())->step_order);

        // และผู้อนุมัติขั้นที่ 1 ต้องกลับมาทำงานได้อีกครั้ง
        $this->assertTrue($this->engine()->authorize($instance, $this->userWithRole($this->hr))['allowed']);
        $this->assertFalse($this->engine()->authorize($instance, $this->userWithRole($this->director))['allowed']);
    }

    public function test_a_returned_request_can_walk_the_chain_again_from_the_start(): void
    {
        $instance = $this->startInstance();

        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->hr));
        $this->engine()->perform($instance->refresh(), 'return', $this->userWithRole($this->deputy), 'ขอข้อมูลเพิ่ม');

        // เดินใหม่ตั้งแต่ขั้นที่ 1 จนจบได้ตามปกติ
        $this->engine()->perform($instance->refresh(), 'approve', $this->userWithRole($this->hr));
        $this->assertSame(2, (int) $this->engine()->currentStep($instance->refresh())->step_order);

        $this->engine()->perform($instance->refresh(), 'approve', $this->userWithRole($this->deputy));
        $result = $this->engine()->perform($instance->refresh(), 'approve', $this->userWithRole($this->director));

        $this->assertTrue($result['final']);
        $this->assertSame(WorkflowInstance::STATUS_COMPLETED, $instance->refresh()->status);
    }

    public function test_return_from_the_first_step_ends_the_instance_as_returned(): void
    {
        $instance = $this->startInstance();

        $result = $this->engine()->perform($instance, 'return', $this->userWithRole($this->hr), 'ให้แก้ไขแล้วยื่นใหม่');

        $this->assertTrue($result['final']);
        $this->assertSame(WorkflowInstance::STATUS_RETURNED, $instance->refresh()->status);
    }

    // ------------------------------------------------------------- delegation

    public function test_a_delegate_may_act_for_the_person_who_delegated(): void
    {
        $instance = $this->startInstance();
        $hrHead = $this->userWithRole($this->hr);
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $hrHead->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $decision = $this->engine()->authorize($instance, $delegate->fresh());

        $this->assertTrue($decision['allowed']);
        $this->assertSame($hrHead->id, $decision['effective_actor_id'], 'ต้องทำงานในนามผู้มอบอำนาจ');
        $this->assertSame($hrHead->id, $decision['delegated_by_id']);

        $this->engine()->perform($instance, 'approve', $delegate->fresh());

        $task = $instance->tasks()->where('sequence', 0)->first();
        $this->assertSame($delegate->id, $task->completed_by, 'ต้องบันทึกผู้กดจริง');
        $this->assertSame($hrHead->id, $task->effective_actor_id, 'ต้องบันทึกผู้ที่ควรเป็นเจ้าของงาน');
        $this->assertSame($hrHead->id, $task->delegated_by_id);
    }

    public function test_an_expired_delegation_grants_nothing(): void
    {
        $instance = $this->startInstance();
        $hrHead = $this->userWithRole($this->hr);
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $hrHead->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertFalse($this->engine()->authorize($instance, $delegate->fresh())['allowed']);

        $this->expectException(WorkflowAuthorizationException::class);
        $this->engine()->perform($instance, 'approve', $delegate->fresh());
    }

    public function test_a_delegation_that_is_not_yet_effective_grants_nothing(): void
    {
        $instance = $this->startInstance();
        $hrHead = $this->userWithRole($this->hr);
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $hrHead->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertFalse($this->engine()->authorize($instance, $delegate->fresh())['allowed']);
    }

    public function test_a_delegation_from_someone_without_the_required_role_grants_nothing(): void
    {
        $instance = $this->startInstance();
        $bystander = User::factory()->create();     // ไม่ได้ถือบทบาทของขั้นตอนใด
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $bystander->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertFalse($this->engine()->authorize($instance, $delegate->fresh())['allowed']);
    }

    // -------------------------------------------------------- version pinning

    public function test_a_running_instance_keeps_its_pinned_version_when_the_workflow_changes(): void
    {
        $instance = $this->startInstance();
        $originalVersion = $instance->workflow_version_id;
        $originalStepCount = $instance->tasks()->count();

        // สายอนุมัติเปลี่ยน: ตัดขั้นสุดท้ายออก
        ApprovalStep::where('step_order', 3)->delete();
        $newVersion = $this->provisioner()->publishCurrentVersion();

        $this->assertNotSame($originalVersion, $newVersion->getKey(), 'ต้องออกเวอร์ชันใหม่เมื่อสายอนุมัติเปลี่ยน');

        $instance->refresh();
        $this->assertSame($originalVersion, $instance->workflow_version_id, 'instance เดิมต้องยัง pin เวอร์ชันเดิม');
        $this->assertSame($originalStepCount, $instance->tasks()->count(), 'จำนวนขั้นของ instance เดิมต้องไม่เปลี่ยน');

        // และยังเดินครบสามขั้นตามเวอร์ชันที่ pin ไว้
        $this->engine()->perform($instance, 'approve', $this->userWithRole($this->hr));
        $this->engine()->perform($instance->refresh(), 'approve', $this->userWithRole($this->deputy));
        $this->assertSame(3, (int) $this->engine()->currentStep($instance->refresh())->step_order);
    }

    public function test_a_new_instance_uses_the_new_version(): void
    {
        $this->startInstance();

        ApprovalStep::where('step_order', 3)->delete();
        $newVersion = $this->provisioner()->publishCurrentVersion();

        $second = $this->startInstance();

        $this->assertSame($newVersion->getKey(), $second->workflow_version_id);
        $this->assertSame(2, $second->tasks()->count());
    }

    public function test_republishing_an_unchanged_workflow_does_not_create_a_new_version(): void
    {
        $first = $this->provisioner()->publishCurrentVersion();
        $second = $this->provisioner()->publishCurrentVersion();

        $this->assertSame($first->getKey(), $second->getKey());
    }

    // ------------------------------------------------- duplicate / concurrent

    public function test_the_same_step_cannot_be_approved_twice(): void
    {
        $instance = $this->startInstance();
        $hrHead = $this->userWithRole($this->hr);

        $this->engine()->perform($instance, 'approve', $hrHead);

        // ครั้งที่สองผู้ใช้คนเดิมไม่ใช่เจ้าของขั้นปัจจุบันแล้ว
        $this->expectException(WorkflowAuthorizationException::class);
        $this->engine()->perform($instance->refresh(), 'approve', $hrHead);
    }

    public function test_two_approvers_of_the_same_step_cannot_both_advance_it(): void
    {
        $instance = $this->startInstance();
        $first = $this->userWithRole($this->hr);
        $second = $this->userWithRole($this->hr);

        $this->engine()->perform($instance, 'approve', $first);

        // คนที่สองถือบทบาทเดียวกัน แต่ขั้นนั้นถูกปิดไปแล้ว
        $this->expectException(WorkflowAuthorizationException::class);
        $this->engine()->perform($instance->refresh(), 'approve', $second);
    }

    public function test_an_action_on_a_finished_instance_is_refused(): void
    {
        $instance = $this->startInstance();
        $this->engine()->perform($instance, 'reject', $this->userWithRole($this->hr), 'ปฏิเสธ');

        $this->expectException(WorkflowAuthorizationException::class);
        $this->engine()->perform($instance->refresh(), 'approve', $this->userWithRole($this->hr));
    }

    // ------------------------------------------------------------------ audit

    public function test_every_action_is_recorded_with_the_full_context(): void
    {
        $instance = $this->startInstance();
        $hrHead = $this->userWithRole($this->hr);
        $delegate = User::factory()->create();

        UserDelegation::create([
            'user_id' => $hrHead->id,
            'delegate_id' => $delegate->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->engine()->perform($instance, 'approve', $delegate->fresh(), 'อนุมัติแทน');

        $action = WorkflowAction::where('workflow_instance_id', $instance->getKey())
            ->where('action', 'approve')
            ->first();

        $this->assertNotNull($action, 'ต้องมีบันทึกการกระทำ');
        $this->assertSame($hrHead->id, $action->effective_actor_id);
        $this->assertSame($hrHead->id, $action->delegated_by_id);
        $this->assertSame('อนุมัติแทน', $action->comment);
        $this->assertNotNull($action->from_step_id);
        $this->assertNotNull($action->to_step_id);
        $this->assertNotNull($action->created_at);

        // การเริ่ม instance ก็ต้องถูกบันทึก
        $this->assertTrue(
            WorkflowAction::where('workflow_instance_id', $instance->getKey())->where('action', 'started')->exists(),
        );
    }

    public function test_the_action_log_cannot_be_rewritten(): void
    {
        $instance = $this->startInstance();
        $action = WorkflowAction::where('workflow_instance_id', $instance->getKey())->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $action->update(['comment' => 'แก้ย้อนหลัง']);
    }

    public function test_the_action_log_cannot_be_deleted(): void
    {
        $instance = $this->startInstance();
        $action = WorkflowAction::where('workflow_instance_id', $instance->getKey())->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $action->delete();
    }

    // ------------------------------------------------------------- transitions

    public function test_available_transitions_come_from_the_pinned_version(): void
    {
        $instance = $this->startInstance();

        $actions = $this->engine()->availableTransitions($instance);
        sort($actions);

        $this->assertSame(['approve', 'cancel', 'reject', 'return'], $actions);
    }

    public function test_available_transitions_are_empty_for_someone_with_no_standing(): void
    {
        $instance = $this->startInstance();
        $outsider = User::factory()->create();

        $this->assertSame([], $this->engine()->availableTransitions($instance, $outsider));
    }

    public function test_an_unsupported_action_is_refused(): void
    {
        $instance = $this->startInstance();

        $this->expectException(WorkflowTransitionException::class);
        $this->engine()->perform($instance, 'escalate', $this->userWithRole($this->hr));
    }

    // -------------------------------------------------------------- idempotency

    public function test_starting_twice_returns_the_same_instance(): void
    {
        $leave = $this->makeLeave();
        $this->provisioner()->publishCurrentVersion();

        $first = $this->engine()->start($this->provisioner()->definition(), $leave, $leave->user_id);
        $second = $this->engine()->start($this->provisioner()->definition(), $leave, $leave->user_id);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(3, $first->tasks()->count());
    }

    public function test_the_transition_set_supports_the_actions_the_phase_requires(): void
    {
        $version = $this->provisioner()->publishCurrentVersion();

        $actions = WorkflowTransition::where('workflow_version_id', $version->getKey())
            ->pluck('action')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['approve', 'cancel', 'reject', 'return'], $actions);
    }
}
