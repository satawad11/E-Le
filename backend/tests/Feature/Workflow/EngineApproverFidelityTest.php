<?php

namespace Tests\Feature\Workflow;

use App\Models\ApprovalStep;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelAssignment;
use App\Models\User;
use App\Models\Workflow\WorkflowInstance;
use App\Services\OrgScopeResolver;
use App\Services\Workflow\ApproverResolverService;
use App\Services\Workflow\LeaveWorkflowProvisioner;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * workflow engine ต้องตอบว่า "ใครอนุมัติขั้นนี้ได้" ตรงกับเส้นทางอนุมัติจริงทุกรูปแบบ
 *
 * เดิม engine แปลงขั้นตอนของใบลาเป็น `assignee_type` แบบหยาบ ขั้นแบบหัวหน้าแผนก
 * รอง ผอ. และ ผอ. ถูกยุบเป็น `org_manager` ตัวเดียวกัน ทำให้ engine ยอมให้
 * หัวหน้าแผนกต้นสังกัดอนุมัติในขั้นของผู้อำนวยการได้ และไม่มีทางถอยไปหาผู้ถือ permission
 * เมื่อยังไม่ได้ตั้งผังหัวหน้าหน่วยงาน — เป็นอุปสรรคของการยก engine ขึ้นเป็นตัวจริง
 *
 * เทสต์ชุดนี้ล็อกว่าคำตอบของทั้งสองทางต้องเท่ากันเสมอ
 */
class EngineApproverFidelityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Leave} ผู้ยื่นพร้อมใบลาที่มี snapshot สายอนุมัติแล้ว */
    private function leaveWithSnapshot(): array
    {
        $dept = Department::create(['name' => 'แผนกทดสอบ engine']);
        $requester = User::factory()->create(['department_id' => $dept->id, 'is_active' => true]);
        $type = LeaveType::create(['name' => 'ลาพักผ่อน']);

        $leave = Leave::create([
            'user_id' => $requester->id, 'leave_type_id' => $type->id,
            'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบความตรงกันของ engine',
            'status' => 'Pending', 'approval_level' => 1,
        ]);

        foreach (ApprovalStep::orderBy('step_order')->get() as $step) {
            LeaveApproval::create([
                'leave_id' => $leave->id, 'stage' => LeaveApproval::STAGE_REQUEST,
                'step_order' => $step->step_order, 'step_name' => $step->name,
                'required_role_id' => $step->role_id, 'approver_type' => $step->approver_type,
                'specific_user_id' => $step->specific_user_id, 'permission_name' => $step->permission_name,
                'status' => 'Pending',
            ]);
        }

        return [$requester, $leave->fresh()];
    }

    private function instanceFor(Leave $leave, User $requester): WorkflowInstance
    {
        $version = app(LeaveWorkflowProvisioner::class)->publishCurrentVersion();

        return app(WorkflowEngine::class)->startOnVersion($version, $leave, $requester->id, false);
    }

    /** ผู้มีสิทธิ์ตามเส้นทางจริง เทียบกับที่ engine ตอบ */
    private function assertEngineMatchesLiveResolver(Leave $leave, User $requester): void
    {
        $snapshot = LeaveApproval::where('leave_id', $leave->id)
            ->where('stage', LeaveApproval::STAGE_REQUEST)
            ->orderBy('step_order')->first();

        $expected = app(ApproverResolverService::class)->resolveEligibleApproverIds($requester, $snapshot);
        sort($expected);

        $engine = app(WorkflowEngine::class)->nextApprovers($this->instanceFor($leave, $requester));
        sort($engine);

        $this->assertSame($expected, $engine);
    }

    public function test_engine_matches_the_live_resolver_for_a_role_step(): void
    {
        $role = Role::firstOrCreate(['name' => 'ผู้อนุมัติทดสอบ', 'guard_name' => 'web']);
        ApprovalStep::create(['name' => 'อนุมัติตามบทบาท', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_ROLE, 'role_id' => $role->id]);

        $approver = User::factory()->create(['is_active' => true]);
        $approver->assignRole($role);

        [$requester, $leave] = $this->leaveWithSnapshot();

        $this->assertEngineMatchesLiveResolver($leave, $requester);
    }

    public function test_engine_matches_the_live_resolver_for_a_specific_user_step(): void
    {
        $designated = User::factory()->create(['is_active' => true]);
        ApprovalStep::create([
            'name' => 'ผู้อนุมัติเฉพาะราย', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_SPECIFIC_USER, 'specific_user_id' => $designated->id,
        ]);

        [$requester, $leave] = $this->leaveWithSnapshot();

        $this->assertEngineMatchesLiveResolver($leave, $requester);
        $this->assertSame([$designated->id], app(WorkflowEngine::class)->nextApprovers($this->instanceFor($leave, $requester)));
    }

    public function test_the_director_step_no_longer_accepts_the_requesters_department_head(): void
    {
        // หัวหน้าแผนกต้นสังกัดของผู้ยื่น
        $unit = OrgUnit::create([
            'uuid' => (string) Str::uuid(), 'code' => 'FID-01', 'name' => 'แผนกวิชาทดสอบ',
            'type' => 'academic', 'effective_from' => '2025-01-01', 'is_active' => true,
        ]);
        $rootUnit = OrgUnit::whereNull('parent_id')->orderBy('id')->first()
            ?? OrgUnit::create([
                'uuid' => (string) Str::uuid(), 'code' => 'ROOT', 'name' => 'สถานศึกษา',
                'type' => 'organization', 'effective_from' => '2025-01-01', 'is_active' => true,
            ]);

        $departmentHead = User::factory()->create(['is_active' => true]);
        $director = User::factory()->create(['is_active' => true]);

        foreach ([[$unit->id, $departmentHead->id], [$rootUnit->id, $director->id]] as [$unitId, $userId]) {
            DB::table('org_unit_managers')->insert([
                'uuid' => (string) Str::uuid(), 'org_unit_id' => $unitId, 'user_id' => $userId,
                'assignment_type' => 'MANAGER', 'scope' => 'UNIT', 'effective_from' => '2025-01-01',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        ApprovalStep::create(['name' => 'ผู้อำนวยการอนุมัติ', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_DIRECTOR, 'role_id' => null]);

        [$requester, $leave] = $this->leaveWithSnapshot();

        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $requester->id,
            'org_unit_id' => $unit->id, 'effective_from' => now()->toDateString(),
        ]);

        $this->assertSame($unit->id, app(OrgScopeResolver::class)->homeOrgUnitId($requester->fresh()));

        $engine = app(WorkflowEngine::class)->nextApprovers($this->instanceFor($leave, $requester));

        // ขั้นของผู้อำนวยการต้องไม่ยอมรับหัวหน้าแผนกต้นสังกัดของผู้ยื่น
        $this->assertContains($director->id, $engine);
        $this->assertNotContains($departmentHead->id, $engine);
        $this->assertEngineMatchesLiveResolver($leave->fresh(), $requester->fresh());
    }

    public function test_engine_falls_back_to_permission_holders_like_the_live_resolver(): void
    {
        // ไม่มีการตั้งผังหัวหน้าหน่วยงานเลย — ทั้งสองทางต้องถอยไปหาผู้ถือสิทธิ์เหมือนกัน
        $hrOfficer = User::factory()->create(['is_active' => true]);
        $hrOfficer->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));

        ApprovalStep::create(['name' => 'เจ้าหน้าที่บุคลากรตรวจสอบ', 'step_order' => 1, 'approver_type' => ApprovalStep::RESOLVER_HR_OFFICER, 'role_id' => null]);

        [$requester, $leave] = $this->leaveWithSnapshot();

        $engine = app(WorkflowEngine::class)->nextApprovers($this->instanceFor($leave, $requester));

        $this->assertContains($hrOfficer->id, $engine);
        $this->assertEngineMatchesLiveResolver($leave, $requester);
    }
}
