<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\LeaveApprovalFlow;
use App\Services\LeaveApproverBlocks;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DELEGATION-01 — การรักษาราชการแทน / ปฏิบัติราชการแทน และการบันทึกแทนของผู้ดูแล
 *
 * มติเจ้าของระบบ 2026-10-16:
 *  - รองรับทั้งรักษาราชการแทนและปฏิบัติราชการแทน พิมพ์ "<ประเภท> <ตำแหน่ง>" ใต้ตำแหน่งผู้ลงนาม
 *  - ทะเบียนการทำหน้าที่แทนบันทึก/รับรองโดยงานบุคลากร (ผู้ใช้ขอได้ แต่มีผลเมื่อรับรองแล้ว)
 *  - ผู้ดูแลระบบลงนามในนามตนเองไม่ได้ — บันทึกแทนผู้มีอำนาจตัวจริงได้อย่างเดียว
 */
class LeaveActingOnBehalfTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $deputy;

    private User $director;

    private User $employee;

    private User $admin;

    private User $other;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('leave.management.manage', 'web');
        $personnel = PersonnelType::where('code', 'civil_servant')->firstOrFail();
        $dept = Department::create(['name' => 'ฝ่ายทดสอบทำหน้าที่แทน']);

        $roles = [];
        foreach (['งานบุคลากร', 'รองผู้อำนวยการ', 'ผู้อำนวยการ'] as $order => $name) {
            $roles[$order] = Role::create(['name' => $name, 'guard_name' => 'web']);
            ApprovalStep::create(['name' => "ขั้น {$name}", 'role_id' => $roles[$order]->id, 'step_order' => $order + 1]);
        }

        $this->type = LeaveType::create(['name' => 'ลาทดสอบทำหน้าที่แทน', 'code_prefix' => 'D']);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2020-01-01', 'period_basis' => 'fiscal_year', 'annual_quota' => 30,
            'exclude_holidays' => true, 'is_active' => true,
        ]);

        $make = fn () => User::factory()->create(['department_id' => $dept->id, 'personnel_type_id' => $personnel->id]);
        $this->hr = $make();
        $this->hr->assignRole($roles[0]);
        $this->deputy = $make();
        $this->deputy->assignRole($roles[1]);
        $this->deputy->profile()->updateOrCreate(['user_id' => $this->deputy->id], ['position' => 'รองผู้อำนวยการ']);
        $this->director = $make();
        $this->director->assignRole($roles[2]);
        $this->director->profile()->updateOrCreate(['user_id' => $this->director->id], ['position' => 'ผู้อำนวยการวิทยาลัยทดสอบ']);
        // ล้าง relation ที่โหลดค้างไว้ตอนสร้าง — คำขอจริงโหลดผู้ใช้ใหม่ทุกครั้ง
        $this->deputy->unsetRelation('profile');
        $this->director->unsetRelation('profile');
        $this->employee = $make();
        $this->other = $make();
        $this->admin = $make();
        $this->admin->givePermissionTo('leave.management.manage');
    }

    private function submit(User $as): Leave
    {
        $start = now()->addWeeks(2)->next(Carbon::MONDAY);

        $id = $this->actingAs($as)->postJson('/api/leaves', [
            'leave_type_id' => $this->type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'reason' => 'ทดสอบทำหน้าที่แทน',
        ])->assertCreated()->json('leave.id');

        return Leave::findOrFail($id);
    }

    private function approve(User $as, Leave $leave, array $payload = [])
    {
        return $this->actingAs($as)->postJson("/api/approvals/{$leave->id}/approve", $payload);
    }

    /** ใบลาที่ผ่านสองขั้นแรกแล้ว รอขั้นผู้อำนวยการ */
    private function leaveAtDirectorStep(): Leave
    {
        $leave = $this->submit($this->employee);
        $this->approve($this->hr, $leave)->assertOk();
        $this->approve($this->deputy, $leave)->assertOk();

        return $leave;
    }

    private function delegate(array $attributes = []): UserDelegation
    {
        return UserDelegation::create($attributes + [
            'user_id' => $this->director->id,
            'delegate_id' => $this->deputy->id,
            'delegation_type' => UserDelegation::TYPE_ON_BEHALF,
            'order_number' => '45/2569',
            'order_date' => '2026-10-01',
            'start_date' => today()->subDay(),
            'end_date' => today()->addMonth(),
            'is_active' => true,
            'status' => UserDelegation::STATUS_APPROVED,
        ]);
    }

    public function test_a_delegate_signs_with_the_acting_capacity_snapshotted_and_printed(): void
    {
        $this->delegate(['delegation_type' => UserDelegation::TYPE_ACTING]);
        $leave = $this->leaveAtDirectorStep();

        $this->approve($this->deputy, $leave)->assertOk();

        $final = $leave->approvals()->where('step_order', 3)->firstOrFail();
        $this->assertSame($this->deputy->id, $final->approver_id);
        $this->assertSame('รองผู้อำนวยการ', $final->approver_position);
        $this->assertSame($this->director->id, $final->delegated_from_user_id);
        $this->assertSame('acting', $final->acting_type);
        $this->assertSame('ผู้อำนวยการวิทยาลัยทดสอบ', $final->acting_position);
        $this->assertSame('คำสั่งที่ 45/2569 ลงวันที่ 01/10/2569', $final->acting_order_ref);
        $this->assertNull($final->recorded_by_user_id);

        $blocks = app(LeaveApproverBlocks::class)->for($leave->fresh());
        $this->assertSame('รักษาราชการแทน ผู้อำนวยการวิทยาลัยทดสอบ', $blocks[2]['acting_line']);
        $this->assertSame('', $blocks[1]['acting_line']);

        $log = LeaveAuditLog::where('leave_id', $leave->id)->where('action', 'approval_approved')->latest('id')->firstOrFail();
        $this->assertSame('delegate', $log->after['capacity']);
        $this->assertSame($this->director->id, $log->after['delegated_from']);
    }

    public function test_the_acting_position_in_the_register_overrides_the_delegators_profile_position(): void
    {
        $this->delegate(['acting_position' => 'ผู้อำนวยการวิทยาลัยเทคนิคทดสอบ']);
        $leave = $this->leaveAtDirectorStep();

        $this->approve($this->deputy, $leave)->assertOk();

        $blocks = app(LeaveApproverBlocks::class)->for($leave->fresh());
        $this->assertSame('ปฏิบัติราชการแทน ผู้อำนวยการวิทยาลัยเทคนิคทดสอบ', $blocks[2]['acting_line']);
    }

    public function test_a_pending_or_revoked_delegation_gives_no_authority(): void
    {
        $delegation = $this->delegate(['status' => UserDelegation::STATUS_PENDING, 'delegate_id' => $this->other->id]);
        $leave = $this->leaveAtDirectorStep();

        $this->approve($this->other, $leave)->assertForbidden();
        $this->assertNotContains($leave->id, array_column($this->actingAs($this->other)->getJson('/api/approvals/pending')->json(), 'id'));

        $delegation->update(['status' => UserDelegation::STATUS_REVOKED, 'is_active' => false]);
        $this->approve($this->other, $leave)->assertForbidden();

        $delegation->update(['status' => UserDelegation::STATUS_APPROVED, 'is_active' => true, 'end_date' => today()->subDay(), 'start_date' => today()->subWeek()]);
        $this->approve($this->other, $leave)->assertForbidden();

        $this->assertSame('Pending', $leave->fresh()->status);
    }

    public function test_an_admin_who_is_not_the_authority_must_name_the_real_signer(): void
    {
        $leave = $this->leaveAtDirectorStep();

        $this->approve($this->admin, $leave)->assertStatus(422);
        $this->approve($this->admin, $leave, ['on_behalf_of' => $this->other->id])->assertStatus(422);
        $this->approve($this->admin, $leave, ['on_behalf_of' => $this->employee->id])->assertStatus(422);

        $this->assertSame(3, (int) $leave->fresh()->approval_level);
    }

    public function test_an_admin_records_the_directors_paper_signature_in_the_directors_name(): void
    {
        $leave = $this->leaveAtDirectorStep();

        $this->approve($this->admin, $leave, ['on_behalf_of' => $this->director->id])->assertOk();

        $final = $leave->approvals()->where('step_order', 3)->firstOrFail();
        $this->assertSame($this->director->id, $final->approver_id);
        $this->assertSame('ผู้อำนวยการวิทยาลัยทดสอบ', $final->approver_position);
        $this->assertSame($this->admin->id, $final->recorded_by_user_id);
        $this->assertNull($final->signature_path);
        $this->assertNull($final->comment);
        $this->assertNull($final->acting_type);
        $this->assertSame('Approved', $leave->fresh()->status);

        $blocks = app(LeaveApproverBlocks::class)->for($leave->fresh());
        $this->assertTrue($blocks[2]['recorded']);
        $this->assertNull($blocks[2]['signature']);

        $log = LeaveAuditLog::where('leave_id', $leave->id)->where('action', 'approval_approved')->latest('id')->firstOrFail();
        $this->assertSame('record', $log->after['capacity']);
        $this->assertSame($this->director->id, $log->after['signer_id']);
    }

    public function test_an_admin_records_a_delegates_signature_with_the_acting_line(): void
    {
        $this->delegate();
        $leave = $this->leaveAtDirectorStep();

        $this->approve($this->admin, $leave, ['on_behalf_of' => $this->deputy->id])->assertOk();

        $final = $leave->approvals()->where('step_order', 3)->firstOrFail();
        $this->assertSame($this->deputy->id, $final->approver_id);
        $this->assertSame($this->director->id, $final->delegated_from_user_id);
        $this->assertSame('on_behalf', $final->acting_type);
        $this->assertSame($this->admin->id, $final->recorded_by_user_id);
    }

    public function test_an_admin_rejection_is_also_recorded_in_the_authoritys_name(): void
    {
        $leave = $this->leaveAtDirectorStep();

        $this->actingAs($this->admin)->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'ไม่อนุญาตตามบันทึก'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/approvals/{$leave->id}/reject", ['comment' => 'ไม่อนุญาตตามบันทึก', 'on_behalf_of' => $this->director->id])->assertOk();

        $final = $leave->approvals()->where('step_order', 3)->firstOrFail();
        $this->assertSame('Rejected', $final->status);
        $this->assertSame($this->director->id, $final->approver_id);
        $this->assertSame($this->admin->id, $final->recorded_by_user_id);
        $this->assertSame('ไม่อนุญาตตามบันทึก', $final->comment);
    }

    public function test_the_pending_list_tells_each_user_in_which_capacity_they_sign(): void
    {
        $this->delegate();
        $leave = $this->leaveAtDirectorStep();

        $asDelegate = collect($this->actingAs($this->deputy)->getJson('/api/approvals/pending')->assertOk()->json())->firstWhere('id', $leave->id);
        $this->assertSame('delegate', $asDelegate['signing']['mode']);
        $this->assertSame('ปฏิบัติราชการแทน ผู้อำนวยการวิทยาลัยทดสอบ', $asDelegate['signing']['acting_line']);

        $asDirector = collect($this->actingAs($this->director)->getJson('/api/approvals/pending')->assertOk()->json())->firstWhere('id', $leave->id);
        $this->assertSame('direct', $asDirector['signing']['mode']);

        $asAdmin = collect($this->actingAs($this->admin)->getJson('/api/approvals/pending')->assertOk()->json())->firstWhere('id', $leave->id);
        $this->assertSame('record', $asAdmin['signing']['mode']);
        $candidates = collect($asAdmin['signing']['record_candidates']);
        $this->assertTrue($candidates->contains('id', $this->director->id));
        $this->assertSame('ปฏิบัติราชการแทน ผู้อำนวยการวิทยาลัยทดสอบ', $candidates->firstWhere('id', $this->deputy->id)['acting_line']);
        $this->assertFalse($candidates->contains('id', $this->employee->id));
    }

    public function test_in_effect_delegates_are_notified_with_the_authority(): void
    {
        $this->delegate(['delegate_id' => $this->other->id]);
        $leave = $this->leaveAtDirectorStep();

        $ids = app(LeaveApprovalFlow::class)->currentApprovers($leave->fresh())->pluck('id')->all();

        $this->assertContains($this->director->id, $ids);
        $this->assertContains($this->other->id, $ids);
    }

    public function test_a_drawn_signature_is_stored_with_the_leave_approval(): void
    {
        Storage::fake('private_uploads');
        $leave = $this->submit($this->employee);
        $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $this->approve($this->hr, $leave, ['signature_base64' => $png])->assertOk();

        $path = $leave->approvals()->where('step_order', 1)->firstOrFail()->signature_path;
        $this->assertStringStartsWith('/storage/signatures/approval_', $path);
        Storage::disk('private_uploads')->assertExists(substr($path, strlen('/storage/')));
    }

    public function test_an_admin_records_a_cancellation_decision_in_the_authoritys_name(): void
    {
        $leave = $this->leaveAtDirectorStep();
        $this->approve($this->director, $leave)->assertOk();
        $this->actingAs($this->employee)->postJson("/api/leaves/{$leave->id}/cancel", ['cancellation_reason' => 'ติดราชการ'])->assertOk();
        $this->actingAs($this->hr)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();
        $this->actingAs($this->deputy)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertOk();

        $this->actingAs($this->admin)->postJson("/api/leaves/{$leave->id}/approve-cancellation")->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/leaves/{$leave->id}/approve-cancellation", ['on_behalf_of' => $this->director->id])->assertOk();

        $this->assertSame('Cancelled', $leave->fresh()->status);
        $step = $leave->cancellationApprovals()->where('step_order', 3)->firstOrFail();
        $this->assertSame($this->director->id, $step->approver_id);
        $this->assertSame($this->admin->id, $step->recorded_by_user_id);
    }

    // ── ใบลาที่ฝ่ายบุคคลบันทึกแทน ────────────────────────────────────────

    private function recordPayload(array $signers): array
    {
        $day = today()->addWeeks(3)->next(Carbon::TUESDAY)->toDateString();

        return [
            'user_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
            'start_date' => $day, 'end_date' => $day, 'reason' => 'บันทึกตามใบลากระดาษ',
            'signers' => $signers,
        ];
    }

    public function test_a_leave_recorded_by_hr_names_the_real_signers_not_the_officer(): void
    {
        $this->delegate();

        $this->actingAs($this->admin)->postJson('/api/admin/leaves', $this->recordPayload([
            1 => $this->hr->id, 2 => $this->deputy->id, 3 => $this->deputy->id,
        ]))->assertCreated();

        $leave = Leave::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame('Approved', $leave->status);
        $steps = $leave->approvals()->orderBy('step_order')->get();
        $this->assertSame([$this->hr->id, $this->deputy->id, $this->deputy->id], $steps->pluck('approver_id')->all());
        $this->assertSame([$this->admin->id], $steps->pluck('recorded_by_user_id')->unique()->values()->all());
        $this->assertSame([null], $steps->pluck('signature_path')->unique()->values()->all());
        $this->assertSame('on_behalf', $steps[2]->acting_type);
        $this->assertSame($this->director->id, $steps[2]->delegated_from_user_id);

        $blocks = app(LeaveApproverBlocks::class)->for($leave->fresh());
        $this->assertNotContains($this->admin->full_name_with_title, array_column($blocks, 'name'));
        $this->assertSame('ปฏิบัติราชการแทน ผู้อำนวยการวิทยาลัยทดสอบ', $blocks[2]['acting_line']);
    }

    public function test_hr_must_name_an_authorised_signer_for_every_step(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/leaves', $this->recordPayload([1 => $this->hr->id, 2 => $this->deputy->id]))
            ->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/api/admin/leaves', $this->recordPayload([1 => $this->hr->id, 2 => $this->deputy->id, 3 => $this->other->id]))
            ->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/api/admin/leaves', $this->recordPayload([1 => $this->employee->id, 2 => $this->deputy->id, 3 => $this->director->id]))
            ->assertStatus(422);

        $this->assertSame(0, Leave::where('user_id', $this->employee->id)->count());
    }

    public function test_hr_sees_who_may_sign_each_step_for_the_chosen_person(): void
    {
        $this->delegate();

        $steps = $this->actingAs($this->admin)->getJson('/api/admin/leaves/record-signers?user_id='.$this->employee->id)->assertOk()->json();

        $this->assertSame([1, 2, 3], array_column($steps, 'step_order'));
        $director = collect($steps[2]['candidates']);
        $this->assertTrue($director->contains('id', $this->director->id));
        $this->assertTrue($director->contains('id', $this->deputy->id));

        $this->actingAs($this->deputy)->getJson('/api/admin/leaves/record-signers?user_id='.$this->employee->id)->assertForbidden();
    }

    public function test_the_hr_head_role_holds_leave_management_after_seeding(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $hrHead = User::factory()->create();
        $hrHead->assignRole('HR Head');

        $this->assertTrue($hrHead->fresh()->can('leave.management.manage'));
        $this->actingAs($hrHead)->getJson('/api/admin/delegations')->assertOk();
    }

    // ── ทะเบียนการทำหน้าที่แทน ──────────────────────────────────────────

    public function test_a_users_own_delegation_request_stays_pending_until_hr_approves_it(): void
    {
        $this->actingAs($this->director)->postJson('/api/profile/delegations', [
            'delegate_id' => $this->deputy->id,
            'delegation_type' => 'acting',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addWeek()->toDateString(),
        ])->assertCreated();

        $delegation = UserDelegation::where('user_id', $this->director->id)->firstOrFail();
        $this->assertSame(UserDelegation::STATUS_PENDING, $delegation->status);
        $this->assertSame($this->director->id, $delegation->requested_by);
        $this->assertTrue(AuditLog::where('action', 'delegation_requested')->where('resource_id', (string) $delegation->id)->exists());

        $leave = $this->leaveAtDirectorStep();
        // คำขอยังไม่ได้รับรอง — รองฯ ลงนามแทนไม่ได้
        $this->approve($this->deputy, $leave)->assertForbidden();

        $this->actingAs($this->admin)->postJson("/api/admin/delegations/{$delegation->id}/approve", ['order_number' => '50/2569'])->assertOk();
        $this->assertSame(UserDelegation::STATUS_APPROVED, $delegation->fresh()->status);
        $this->assertSame($this->admin->id, $delegation->fresh()->approved_by);
        $this->assertTrue(AuditLog::where('action', 'delegation_approved')->where('resource_id', (string) $delegation->id)->exists());

        $this->approve($this->deputy, $leave)->assertOk();
        $this->assertSame('acting', $leave->approvals()->where('step_order', 3)->firstOrFail()->acting_type);
    }

    public function test_a_user_cannot_delegate_to_themselves(): void
    {
        $this->actingAs($this->director)->postJson('/api/profile/delegations', [
            'delegate_id' => $this->director->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addWeek()->toDateString(),
        ])->assertStatus(422);

        $this->assertSame(0, UserDelegation::count());
    }

    public function test_only_leave_managers_can_use_the_delegation_register(): void
    {
        $this->actingAs($this->deputy)->getJson('/api/admin/delegations')->assertForbidden();
        $this->actingAs($this->deputy)->postJson('/api/admin/delegations', [])->assertForbidden();
        $delegation = $this->delegate();
        $this->actingAs($this->deputy)->postJson("/api/admin/delegations/{$delegation->id}/revoke")->assertForbidden();
        $this->actingAs($this->deputy)->postJson("/api/admin/delegations/{$delegation->id}/approve")->assertForbidden();
        $this->actingAs($this->deputy)->putJson("/api/admin/delegations/{$delegation->id}", [])->assertForbidden();
    }

    public function test_hr_records_an_order_and_revokes_it_without_deleting_history(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/delegations', [
            'user_id' => $this->director->id,
            'delegate_id' => $this->deputy->id,
            'delegation_type' => 'on_behalf',
            'order_number' => '12/2569',
            'order_date' => today()->toDateString(),
            'start_date' => today()->toDateString(),
            'end_date' => today()->addMonth()->toDateString(),
        ])->assertCreated();

        $delegation = UserDelegation::findOrFail($response->json('delegation.id'));
        $this->assertSame(UserDelegation::STATUS_APPROVED, $delegation->status);
        $this->assertTrue(AuditLog::where('action', 'delegation_created')->where('resource_id', (string) $delegation->id)->exists());

        $list = $this->actingAs($this->admin)->getJson('/api/admin/delegations')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $delegation->id);
        $this->assertTrue($row['in_effect']);
        $this->assertSame('ปฏิบัติราชการแทน ผู้อำนวยการวิทยาลัยทดสอบ', $row['acting_line']);
        $this->assertArrayNotHasKey('email', $row['user']);

        $this->actingAs($this->admin)->postJson("/api/admin/delegations/{$delegation->id}/revoke", ['reason' => 'กลับมาปฏิบัติราชการ'])->assertOk();
        $delegation->refresh();
        $this->assertSame(UserDelegation::STATUS_REVOKED, $delegation->status);
        $this->assertFalse($delegation->is_active);
        $this->assertSame($this->admin->id, $delegation->revoked_by);
        $this->assertTrue(AuditLog::where('action', 'delegation_revoked')->where('resource_id', (string) $delegation->id)->exists());

        $this->actingAs($this->admin)->putJson("/api/admin/delegations/{$delegation->id}", [
            'user_id' => $this->director->id, 'delegate_id' => $this->other->id, 'delegation_type' => 'acting',
            'start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString(),
        ])->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson("/api/admin/delegations/{$delegation->id}")->assertStatus(405);
        $this->assertDatabaseHas('user_delegations', ['id' => $delegation->id]);
    }

    public function test_the_register_rejects_self_delegation_and_unknown_types(): void
    {
        $base = [
            'user_id' => $this->director->id, 'delegation_type' => 'on_behalf',
            'start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString(),
        ];

        $this->actingAs($this->admin)->postJson('/api/admin/delegations', $base + ['delegate_id' => $this->director->id])
            ->assertStatus(422)->assertJsonValidationErrors('delegate_id');
        $this->actingAs($this->admin)->postJson('/api/admin/delegations', ['delegation_type' => 'boss'] + $base + ['delegate_id' => $this->deputy->id])
            ->assertStatus(422)->assertJsonValidationErrors('delegation_type');
        $this->actingAs($this->admin)->postJson('/api/admin/delegations', ['end_date' => today()->subDay()->toDateString()] + $base + ['delegate_id' => $this->deputy->id])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');

        $this->assertSame(0, UserDelegation::count());
    }
}
