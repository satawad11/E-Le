<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowShadowComparison;
use App\Models\Workflow\WorkflowVersion;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use App\Services\Workflow\SarabanWorkflowProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-05 — authorization ต่อ workflow action, IDOR และการยืนยันว่า
 * ใบลาที่ค้างอยู่เดิมยังเดินต่อได้เหมือนเดิมหลังเปิด shadow mode
 *
 * เทสต์ชุดนี้ยิงผ่าน HTTP จริง เพื่อพิสูจน์ว่า **การเพิ่ม shadow mode
 * ไม่ได้เปลี่ยนพฤติกรรมของ endpoint อนุมัติแม้แต่น้อย**
 */
class WorkflowAuthorizationTest extends TestCase
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
        Permission::findOrCreate('leave.management.manage', 'web');

        ApprovalStep::create(['step_order' => 1, 'role_id' => $this->hr->id, 'name' => 'ขั้นที่ 1']);
        ApprovalStep::create(['step_order' => 2, 'role_id' => $this->deputy->id, 'name' => 'ขั้นที่ 2']);
        ApprovalStep::create(['step_order' => 3, 'role_id' => $this->director->id, 'name' => 'ขั้นที่ 3']);

        $this->leaveType = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->name);

        return $user->fresh();
    }

    private function pendingLeave(?User $owner = null): Leave
    {
        $owner ??= User::factory()->create();

        $leave = Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reserved_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Pending',
            'approval_level' => 1,
        ]);

        foreach (ApprovalStep::orderBy('step_order')->get() as $step) {
            LeaveApproval::create([
                'leave_id' => $leave->id,
                'step_order' => $step->step_order,
                'step_name' => $step->name,
                'required_role_id' => $step->role_id,
                'status' => 'Pending',
            ]);
        }

        LeaveBalance::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->leaveType->id,
            'year' => (int) date('Y'),
            'quota' => 30,
            'used' => 0,
            'reserved' => 2,
        ]);

        return $leave->fresh();
    }

    // ------------------------------------------------- ใบลาเดิมยังเดินต่อได้

    public function test_a_pending_leave_still_walks_its_chain_exactly_as_before(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/approve", ['comment' => 'ผ่าน'])
            ->assertOk();

        $leave->refresh();
        $this->assertSame('Pending', $leave->status);
        $this->assertSame(2, (int) $leave->approval_level, 'ต้องเลื่อนไปขั้นที่ 2 ตามเดิม');

        $this->actingAs($this->userWithRole($this->deputy))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertOk();

        $this->assertSame(3, (int) $leave->fresh()->approval_level);

        $this->actingAs($this->userWithRole($this->director))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertOk();

        $this->assertSame('Approved', $leave->fresh()->status, 'ขั้นสุดท้ายต้องทำให้ใบลาได้รับอนุมัติ');
    }

    public function test_rejecting_a_pending_leave_behaves_as_before(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'เอกสารไม่ครบ'])
            ->assertOk();

        $this->assertSame('Rejected', $leave->fresh()->status);
    }

    // --------------------------------------------------------- authorization

    public function test_an_approver_from_a_later_step_cannot_approve_first(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->director))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertForbidden();

        $this->assertSame(1, (int) $leave->fresh()->approval_level, 'สถานะต้องไม่ขยับ');
    }

    public function test_a_user_with_no_role_cannot_approve(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertForbidden();
    }

    public function test_nobody_may_approve_their_own_leave(): void
    {
        $owner = $this->userWithRole($this->hr);
        $leave = $this->pendingLeave($owner);

        $this->actingAs($owner)
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertForbidden();

        $this->assertSame('Pending', $leave->fresh()->status);
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/reject")
            ->assertStatus(422);

        $this->assertSame('Pending', $leave->fresh()->status);
    }

    // ------------------------------------------------------------------ IDOR

    public function test_one_approver_cannot_act_on_another_persons_leave_through_a_guessed_id(): void
    {
        $target = $this->pendingLeave();

        // ผู้ใช้ที่ไม่มีบทบาทใดเลย เดา id ของใบลาคนอื่น
        $this->actingAs(User::factory()->create())
            ->postJson("/api/approvals/{$target->id}/approve")
            ->assertForbidden();

        $this->assertDatabaseHas('leaves', ['id' => $target->id, 'status' => 'Pending', 'approval_level' => 1]);
    }

    public function test_acting_on_a_leave_that_does_not_exist_is_a_not_found(): void
    {
        $this->actingAs($this->userWithRole($this->hr))
            ->postJson('/api/approvals/999999/approve')
            ->assertNotFound();
    }

    public function test_an_already_finished_leave_cannot_be_approved_again(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'ไม่อนุมัติ'])
            ->assertOk();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertStatus(422);
    }

    // --------------------------------------------------- shadow ไม่รบกวนของจริง

    public function test_shadow_mode_records_a_comparison_without_changing_the_outcome(): void
    {
        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertOk();

        $comparisons = WorkflowShadowComparison::where('subject_id', $leave->id)->get();

        $this->assertNotEmpty($comparisons, 'shadow mode ต้องบันทึกผลเทียบ');
        $this->assertTrue(
            $comparisons->every(fn (WorkflowShadowComparison $row) => $row->matched),
            'ผล engine ต้องตรงกับตรรกะเดิม',
        );

        // และ instance ต้องเป็น shadow เสมอ — ยังไม่ใช่ผู้ตัดสินสถานะจริง
        $this->assertTrue(WorkflowInstance::where('subject_id', $leave->id)->first()->is_shadow);
    }

    public function test_turning_shadow_mode_off_leaves_the_approval_path_working(): void
    {
        config(['workflow.shadow_mode' => false]);

        $leave = $this->pendingLeave();

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertOk();

        $this->assertSame(2, (int) $leave->fresh()->approval_level);
        $this->assertSame(0, WorkflowShadowComparison::where('subject_id', $leave->id)->count());
    }

    /**
     * ถ้า shadow ล้มด้วยเหตุใดก็ตาม การอนุมัติจริงต้องไม่ล้มตาม
     */
    public function test_a_broken_shadow_never_breaks_the_real_approval(): void
    {
        $leave = $this->pendingLeave();

        // ทำให้ engine หาเวอร์ชันไม่เจอ — สถานการณ์ที่ shadow ต้องยอมแพ้เงียบ ๆ
        WorkflowVersion::query()->update(['status' => 'retired']);

        $this->actingAs($this->userWithRole($this->hr))
            ->postJson("/api/approvals/{$leave->id}/approve")
            ->assertOk();

        $this->assertSame(2, (int) $leave->fresh()->approval_level);
    }

    // ------------------------------------------------------- Saraban (05-5)

    public function test_the_saraban_workflow_is_only_prepared_not_switched_on(): void
    {
        $version = app(SarabanWorkflowProvisioner::class)->publishDraft();

        $this->assertSame('draft', $version->status, 'สายงานสารบรรณต้องยังเป็น draft');
        $this->assertFalse($version->definition->is_active);
        $this->assertNull($version->definition->activeVersion(), 'ต้องไม่มีเวอร์ชันที่รับ instance ได้');
        $this->assertSame(0, WorkflowInstance::where('subject_type', 'App\\Models\\Saraban\\Document')->count());
    }

    public function test_preparing_the_saraban_workflow_changes_no_permission(): void
    {
        $before = Permission::orderBy('name')->pluck('name')->all();
        $beforeRoleGrants = DB::table('role_has_permissions')->count();

        app(SarabanWorkflowProvisioner::class)->publishDraft();

        $this->assertSame($before, Permission::orderBy('name')->pluck('name')->all(), 'ต้องไม่มี permission เพิ่มหรือหาย');
        $this->assertSame($beforeRoleGrants, DB::table('role_has_permissions')->count(), 'ต้องไม่มีการแจกสิทธิ์เปลี่ยน');
    }
}
