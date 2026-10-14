<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendarException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * การตั้งค่าที่กระทบสิทธิ์/การนับวันลา ต้องมีเวอร์ชันและ audit (PROJECT_RULES ข้อ 7–8, AGENTS.md)
 */
class LeaveSettingsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        foreach (['leave.policy.manage', 'manage_leave_types', 'leave.calendar.manage', 'manage_workflow_steps'] as $name) {
            $this->admin->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->type = LeaveType::create(['name' => 'ลาทดสอบตั้งค่า', 'code_prefix' => 'S']);
        $this->policy = LeavePolicy::create([
            'personnel_type_id' => PersonnelType::where('code', 'civil_servant')->value('id'), 'leave_type_id' => $this->type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10, 'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'personnel_type_id' => $this->policy->personnel_type_id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 15, 'period_basis' => 'fiscal_year', 'is_active' => true,
        ], $overrides);
    }

    private function referenceByLeave(): Leave
    {
        return Leave::create([
            'user_id' => User::factory()->create(['department_id' => Department::create(['name' => 'ทดสอบ'])->id])->id,
            'leave_type_id' => $this->type->id, 'leave_policy_id' => $this->policy->id,
            'start_date' => '2025-11-03', 'end_date' => '2025-11-03', 'total_days' => 1, 'reason' => 'ใช้กฎชุดเดิม', 'status' => 'Approved',
        ]);
    }

    public function test_unused_policy_is_still_edited_in_place(): void
    {
        $this->actingAs($this->admin)->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload())->assertOk();

        $this->assertSame(1, LeavePolicy::where('leave_type_id', $this->type->id)->count());
        $this->assertEquals(15, $this->policy->fresh()->annual_quota);
    }

    public function test_policy_referenced_by_a_leave_cannot_be_overwritten(): void
    {
        $this->referenceByLeave();

        $this->actingAs($this->admin)->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->actingAs($this->admin)->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['effective_from' => '2026-10-01', 'leave_type_id' => LeaveType::create(['name' => 'อื่น'])->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');

        $this->assertEquals(10, $this->policy->fresh()->annual_quota);
        $this->assertSame(1, LeavePolicy::where('leave_type_id', $this->type->id)->count());
    }

    public function test_editing_a_referenced_policy_creates_a_new_version_and_closes_the_old_one(): void
    {
        $leave = $this->referenceByLeave();

        $res = $this->actingAs($this->admin)->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['effective_from' => '2026-10-01']))
            ->assertCreated()->assertJsonPath('versioned', true);

        $old = $this->policy->fresh();
        $new = LeavePolicy::findOrFail($res->json('policy.id'));
        $this->assertEquals(10, $old->annual_quota);
        $this->assertSame('2026-09-30', $old->effective_to->toDateString());
        $this->assertEquals(15, $new->annual_quota);
        $this->assertSame('2026-10-01', $new->effective_from->toDateString());
        $this->assertSame($old->id, $leave->fresh()->leave_policy_id);

        $audit = AuditLog::where('action', 'leave_policy.versioned')->where('resource_id', (string) $new->id)->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertEquals(10, $audit->before['annual_quota']);
    }

    public function test_leave_type_changes_are_audited(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/leave-types', ['name' => 'ลาใหม่'])->assertCreated()->json('leave_type.id');
        $this->actingAs($this->admin)->putJson("/api/admin/leave-types/{$id}", ['name' => 'ลาใหม่ (แก้)'])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/admin/leave-types/{$id}")->assertOk();

        $prefix = 'leave_type.';
        $actions = AuditLog::where('resource_id', (string) $id)->where('action', 'like', $prefix.'%')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['leave_type.created', 'leave_type.updated', 'leave_type.deleted'], $actions);
        $updated = AuditLog::where('action', 'leave_type.updated')->firstOrFail();
        $this->assertSame('ลาใหม่', $updated->before['name']);
        $this->assertSame('ลาใหม่ (แก้)', $updated->after['name']);
    }

    public function test_work_calendar_changes_are_audited(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/work-calendar', ['date' => '2026-11-02', 'day_type' => 'holiday', 'name' => 'หยุดพิเศษ'])->assertCreated()->json('id');
        $this->actingAs($this->admin)->putJson("/api/admin/work-calendar/{$id}", ['day_type' => 'working_day'])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/admin/work-calendar/{$id}")->assertNoContent();

        $this->assertNull(WorkCalendarException::find($id));
        $prefix = 'work_calendar.';
        $actions = AuditLog::where('resource_id', (string) $id)->where('action', 'like', $prefix.'%')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['work_calendar.created', 'work_calendar.updated', 'work_calendar.deleted'], $actions);
        $this->assertSame('holiday', AuditLog::where('action', 'work_calendar.updated')->firstOrFail()->before['day_type']);
    }

    public function test_approval_workflow_changes_are_audited_with_previous_steps(): void
    {
        $role = Role::create(['name' => 'ผู้อนุมัติทดสอบ', 'guard_name' => 'web']);
        ApprovalStep::create(['name' => 'ขั้นเดิม', 'role_id' => $role->id, 'step_order' => 1]);

        $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps', ['steps' => [
            ['name' => 'ขั้นใหม่ 1', 'approver_type' => 'ROLE', 'role_id' => $role->id],
            ['name' => 'ขั้นใหม่ 2', 'approver_type' => 'ROLE', 'role_id' => $role->id],
        ]])->assertOk();

        $audit = AuditLog::where('action', 'approval_workflow.updated')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(['ขั้นเดิม'], array_column($audit->before['steps'], 'name'));
        $this->assertSame(['ขั้นใหม่ 1', 'ขั้นใหม่ 2'], array_column($audit->after['steps'], 'name'));
    }
}
