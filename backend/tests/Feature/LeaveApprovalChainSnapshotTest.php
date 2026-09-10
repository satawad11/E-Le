<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\LeaveApproval;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\PersonnelAssignment;
use App\Models\PersonnelType;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * สายอนุมัติแบบ end-to-end: ตั้งค่า → ยื่น → เห็นในรายการ → อนุมัติ
 *
 * เทสต์เดิมตรวจ ApproverResolverService โดยป้อน step เป็น array ที่มี `approver_type`
 * ให้เอง จึงผ่านทั้งที่ของจริงพัง — เพราะตอนยื่นใบลา snapshot ไม่ได้คัดลอก
 * `approver_type` ลงไปด้วย ขั้นตอนทุกแบบเลยกลายเป็น "ตาม role" หมด
 *
 * ชุดนี้จึงยิงผ่าน HTTP ตั้งแต่ต้นจนจบเสมอ
 */
class LeaveApprovalChainSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: LeaveType} ผู้ยื่นที่มีนโยบายลาพร้อมใช้ */
    private function requesterWithPolicy(): array
    {
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $type = LeaveType::create(['name' => 'ลาทดสอบสายอนุมัติ', 'code_prefix' => 'W']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10, 'period_basis' => 'fiscal_year',
            'exclude_holidays' => true, 'allow_half_day' => true, 'is_active' => true,
        ]);
        $dept = OrgUnit::factory()->create(['name' => 'ทดสอบสายอนุมัติ']);
        $user = User::factory()->create(['org_unit_id' => $dept->id, 'personnel_type_id' => $personnel->id]);

        return [$user, $type];
    }

    private function submitLeave(User $requester, LeaveType $type): array
    {
        $day = now()->addWeek()->nextWeekday()->toDateString();

        return $this->actingAs($requester)->postJson('/api/leaves', [
            'leave_type_id' => $type->id,
            'start_date' => $day,
            'end_date' => $day,
            'reason' => 'ทดสอบสายอนุมัติ',
        ])->assertCreated()->json('leave');
    }

    /** ตั้ง $head เป็นหัวหน้าหน่วยงานต้นสังกัดของ $requester */
    private function makeDepartmentHead(User $head, User $requester): int
    {
        $unitId = app(OrgScopeResolver::class)->homeOrgUnitId($requester);

        if ($unitId === null) {
            $unit = OrgUnit::create([
                'uuid' => (string) Str::uuid(), 'code' => 'UNIT-'.Str::random(4),
                'name' => 'แผนกวิชาทดสอบ', 'type' => 'academic',
                'effective_from' => '2025-01-01', 'is_active' => true,
            ]);
            PersonnelAssignment::create([
                'uuid' => (string) Str::uuid(), 'user_id' => $requester->id,
                'org_unit_id' => $unit->id, 'effective_from' => '2025-01-01',
            ]);
            $unitId = $unit->id;
        }

        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(), 'org_unit_id' => $unitId, 'user_id' => $head->id,
            'assignment_type' => 'MANAGER', 'scope' => 'UNIT', 'note' => 'หัวหน้าแผนกทดสอบ',
            'effective_from' => '2025-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $unitId;
    }

    public function test_department_head_step_lets_the_actual_head_approve(): void
    {
        [$requester, $type] = $this->requesterWithPolicy();
        $head = User::factory()->create();
        $this->makeDepartmentHead($head, $requester);

        ApprovalStep::create([
            'name' => 'หัวหน้าแผนกต้นสังกัด', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null,
        ]);

        $leave = $this->submitLeave($requester, $type);

        // snapshot ต้องผนึกวิธีหาตัวผู้อนุมัติไว้ ไม่ใช่แค่ role
        $this->assertDatabaseHas('leave_approvals', [
            'leave_id' => $leave['id'], 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD,
        ]);

        $this->actingAs($head)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Approved']);
    }

    public function test_specific_user_step_does_not_open_approval_to_everyone(): void
    {
        [$requester, $type] = $this->requesterWithPolicy();
        $designated = User::factory()->create();
        $bystander = User::factory()->create();

        ApprovalStep::create([
            'name' => 'ผู้อนุมัติเฉพาะราย', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_SPECIFIC_USER,
            'specific_user_id' => $designated->id, 'role_id' => null,
        ]);

        $leave = $this->submitLeave($requester, $type);

        // ผู้ใช้ทั่วไปที่ไม่เกี่ยวข้องต้องอนุมัติไม่ได้ (เดิมขั้นตอนแบบนี้เปิดให้ทุกคน)
        $this->actingAs($bystander)->postJson("/api/approvals/{$leave['id']}/approve")->assertForbidden();
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Pending']);

        $this->actingAs($designated)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();
        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Approved']);
    }

    public function test_pending_list_matches_who_can_actually_approve(): void
    {
        [$requester, $type] = $this->requesterWithPolicy();
        $head = User::factory()->create();
        $bystander = User::factory()->create();
        $this->makeDepartmentHead($head, $requester);

        ApprovalStep::create([
            'name' => 'หัวหน้าแผนกต้นสังกัด', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null,
        ]);

        $leave = $this->submitLeave($requester, $type);

        $this->actingAs($head)->getJson('/api/approvals/pending')
            ->assertOk()->assertJsonPath('0.id', $leave['id']);

        $this->actingAs($bystander)->getJson('/api/approvals/pending')->assertOk()->assertJsonCount(0);

        // ไม่มีใครอนุมัติใบลาของตัวเองได้ จึงไม่ควรเห็นในรายการที่ต้องอนุมัติ
        $this->actingAs($requester)->getJson('/api/approvals/pending')->assertOk()->assertJsonCount(0);
    }

    public function test_changing_the_chain_later_does_not_change_leaves_already_submitted(): void
    {
        [$requester, $type] = $this->requesterWithPolicy();
        $original = User::factory()->create();
        $replacement = User::factory()->create();

        ApprovalStep::create([
            'name' => 'ผู้อนุมัติเฉพาะราย', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_SPECIFIC_USER,
            'specific_user_id' => $original->id, 'role_id' => null,
        ]);

        $leave = $this->submitLeave($requester, $type);

        // ผู้ดูแลเปลี่ยนสายอนุมัติหลังจากใบลาถูกยื่นไปแล้ว
        ApprovalStep::query()->update(['specific_user_id' => $replacement->id]);

        $this->actingAs($replacement)->postJson("/api/approvals/{$leave['id']}/approve")->assertForbidden();
        $this->actingAs($original)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();
    }

    public function test_changing_the_approval_chain_is_written_to_the_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $this->actingAs($admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [['name' => 'ผู้อำนวยการ', 'approver_type' => 'DIRECTOR', 'role_id' => null]],
        ])->assertOk();

        // การเปลี่ยนกติกาว่าใครอนุมัติใบลาได้ ต้องตรวจย้อนหลังได้เสมอ
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'approval_workflow_updated',
            'resource_type' => 'ApprovalStep',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_saving_the_chain_keeps_role_empty_for_automatic_steps(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $this->actingAs($admin)->postJson('/api/admin/workflow-steps', [
            'steps' => [
                ['name' => 'หัวหน้าแผนกต้นสังกัด', 'approver_type' => 'DEPARTMENT_HEAD', 'role_id' => null],
                ['name' => 'ผู้อำนวยการ', 'approver_type' => 'DIRECTOR', 'role_id' => null],
            ],
        ])->assertOk();

        // เดิมระบบเติม role (Super Admin/Director/HR Head) ให้เงียบ ๆ ทำให้สิทธิ์อนุมัติรั่ว
        $this->assertDatabaseHas('approval_steps', ['step_order' => 1, 'role_id' => null]);
        $this->assertDatabaseHas('approval_steps', ['step_order' => 2, 'role_id' => null]);
    }

    public function test_cancellation_of_approved_leave_uses_the_same_approver_rules(): void
    {
        [$requester, $type] = $this->requesterWithPolicy();
        $head = User::factory()->create();
        $bystander = User::factory()->create();
        $this->makeDepartmentHead($head, $requester);

        ApprovalStep::create([
            'name' => 'หัวหน้าแผนกต้นสังกัด', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null,
        ]);

        $leave = $this->submitLeave($requester, $type);
        $this->actingAs($head)->postJson("/api/approvals/{$leave['id']}/approve")->assertOk();

        $this->actingAs($requester)->postJson("/api/leaves/{$leave['id']}/cancel", [
            'cancellation_reason' => 'ขอยกเลิกเพราะภารกิจเปลี่ยน',
        ])->assertOk();

        $this->actingAs($bystander)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();
        $this->actingAs($requester)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertForbidden();
        $this->actingAs($head)->postJson("/api/leaves/{$leave['id']}/approve-cancellation")->assertOk();

        $this->assertDatabaseHas('leaves', ['id' => $leave['id'], 'status' => 'Cancelled', 'cancellation_status' => 'Approved']);
    }

    public function test_preview_shows_who_would_actually_approve_for_a_given_requester(): void
    {
        [$requester] = $this->requesterWithPolicy();
        $head = User::factory()->create();
        $this->makeDepartmentHead($head, $requester);

        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        ApprovalStep::create([
            'name' => 'หัวหน้าแผนกต้นสังกัด', 'step_order' => 1,
            'approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/workflow-steps/preview', ['requester_id' => $requester->id])
            ->assertOk()
            ->assertJsonPath('steps.0.approvers.0.id', $head->id)
            ->assertJsonPath('steps.0.warning', null);
    }

    public function test_preview_warns_when_a_step_would_leave_the_request_stuck(): void
    {
        [$requester] = $this->requesterWithPolicy();
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        // ขั้นตอนหัวหน้าแผนก แต่ยังไม่มีใครถูกแต่งตั้งเป็นหัวหน้าหน่วยงานของผู้ยื่น
        $response = $this->actingAs($admin)->postJson('/api/admin/workflow-steps/preview', [
            'requester_id' => $requester->id,
            'steps' => [['name' => 'หัวหน้าแผนกต้นสังกัด', 'approver_type' => 'DEPARTMENT_HEAD', 'role_id' => null]],
        ])->assertOk();

        $this->assertSame([], $response->json('steps.0.approvers'));
        $this->assertNotNull($response->json('steps.0.warning'));
    }

    public function test_preview_requires_the_workflow_management_permission(): void
    {
        [$requester] = $this->requesterWithPolicy();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson('/api/admin/workflow-steps/preview', [
            'requester_id' => $requester->id,
        ])->assertForbidden();
    }

    public function test_snapshot_without_resolver_information_denies_instead_of_allowing_everyone(): void
    {
        [$requester, $type] = $this->requesterWithPolicy();
        $bystander = User::factory()->create();

        ApprovalStep::create(['name' => 'ขั้นตอนเก่าไม่มีข้อมูล', 'step_order' => 1, 'role_id' => null]);

        $leave = $this->submitLeave($requester, $type);

        // จำลองใบลาเก่าที่ snapshot ไม่มีข้อมูลวิธีหาตัวผู้อนุมัติเลย
        LeaveApproval::where('leave_id', $leave['id'])->update([
            'approver_type' => null, 'required_role_id' => null,
            'specific_user_id' => null, 'permission_name' => null,
        ]);

        $this->actingAs($bystander)->postJson("/api/approvals/{$leave['id']}/approve")->assertForbidden();
    }
}
