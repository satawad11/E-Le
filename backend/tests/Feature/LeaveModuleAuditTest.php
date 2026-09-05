<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\LeaveAttendanceSync;
use App\Services\LeaveVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeaveModuleAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 09:00:00'));
        $personnel = PersonnelType::firstOrCreate(['code' => 'civil_servant'], ['name' => 'ประเภททดสอบ']);
        $this->owner = User::factory()->create(['personnel_type_id' => $personnel->id]);
        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));
        $this->type = LeaveType::create(['name' => 'ลาทดสอบตรวจสอบ', 'code_prefix' => 'AUD', 'max_days' => 30]);
        LeavePolicy::create([
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $this->type->id,
            'effective_from' => '2026-01-01', 'annual_quota' => 30,
            'notice_period_days' => 0, 'allow_past_dates' => true,
            'exclude_holidays' => false, 'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);
    }

    private function payload(): array
    {
        return [
            'leave_type_id' => $this->type->id, 'start_date' => '2026-09-07',
            'end_date' => '2026-09-08', 'reason' => 'ข้อมูลทดสอบการตรวจสอบ',
        ];
    }

    public function test_another_users_draft_cannot_be_opened_directly_by_a_manager(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/leaves', [
            ...$this->payload(), 'save_as_draft' => true,
        ])->assertCreated()->json('leave.id');

        $this->actingAs($this->manager)->getJson("/api/leaves/{$id}")->assertForbidden();
    }

    public function test_approved_leave_cannot_be_overwritten_through_generic_update(): void
    {
        $leave = Leave::create([
            ...$this->payload(), 'user_id' => $this->owner->id,
            'status' => 'Approved', 'total_days' => 2, 'paid_days' => 2,
        ]);

        $this->actingAs($this->manager)->putJson("/api/leaves/{$leave->id}", [
            ...$this->payload(), 'end_date' => '2026-09-17',
        ])->assertUnprocessable();
        $this->assertSame('2026-09-08', $leave->fresh()->end_date->toDateString());
    }

    public function test_admin_recording_an_approved_leave_consumes_the_balance(): void
    {
        $id = $this->actingAs($this->manager)->postJson('/api/admin/leaves', [
            ...$this->payload(), 'user_id' => $this->owner->id,
        ])->assertCreated()->json('leave.id');
        $this->assertSame('Approved', Leave::findOrFail($id)->status);
        $balance = LeaveBalance::where('user_id', $this->owner->id)->where('leave_type_id', $this->type->id)->firstOrFail();
        $this->assertSame(2.0, (float) $balance->used);
        $this->assertSame(0.0, (float) $balance->reserved);
    }

    public function test_fiscal_year_leave_must_be_split_at_the_period_boundary(): void
    {
        $this->actingAs($this->owner)->postJson('/api/leaves', [
            ...$this->payload(), 'start_date' => '2026-09-30', 'end_date' => '2026-10-02',
        ])->assertUnprocessable();
    }

    public function test_draft_privacy_also_applies_to_super_admin_documents_and_evidence(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/leaves', [
            ...$this->payload(), 'save_as_draft' => true,
        ])->assertCreated()->json('leave.id');
        $this->manager->assignRole(Role::findOrCreate('Super Admin', 'web'));
        foreach (['', '/pdf', '/word', '/evidences'] as $suffix) {
            $this->actingAs($this->manager)->getJson("/api/leaves/{$id}{$suffix}")->assertForbidden();
        }
        $this->actingAs($this->owner)->getJson("/api/leaves/{$id}")->assertOk();
    }

    public function test_admin_recording_seals_content_syncs_attendance_and_has_no_pending_workflow(): void
    {
        $id = $this->actingAs($this->manager)->postJson('/api/admin/leaves', [
            ...$this->payload(), 'user_id' => $this->owner->id,
        ])->assertCreated()->json('leave.id');
        $leave = Leave::findOrFail($id);
        $this->assertSame(app(LeaveVerificationService::class)->contentHash($leave), $leave->content_hash);
        $this->assertSame(0, $leave->approvals()->count());
        $this->assertTrue(Attendance::where('user_id', $this->owner->id)->whereDate('date', '2026-09-07')->where('status', 'Leave')->exists());
        $this->assertDatabaseMissing('workflow_instances', ['subject_type' => $leave->getMorphClass(), 'subject_id' => $id]);
        $this->assertDatabaseHas('leave_audit_logs', ['leave_id' => $id, 'user_id' => $this->manager->id, 'action' => 'created_approved']);
    }

    public function test_admin_recording_rolls_back_all_changes_when_attendance_fails(): void
    {
        $this->mock(LeaveAttendanceSync::class, function ($mock) {
            $mock->shouldReceive('apply')->once()->andThrow(new \RuntimeException('ทดสอบ rollback'));
        });
        $this->actingAs($this->manager)->postJson('/api/admin/leaves', [
            ...$this->payload(), 'user_id' => $this->owner->id,
        ])->assertStatus(500);
        $this->assertDatabaseMissing('leaves', ['user_id' => $this->owner->id]);
        $this->assertDatabaseMissing('leave_balances', ['user_id' => $this->owner->id]);
        $this->assertDatabaseCount('leave_action_logs', 0);
    }

    public function test_generic_update_recalculates_own_draft_but_preserves_submitted_records(): void
    {
        $this->owner->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));
        $id = $this->actingAs($this->owner)->postJson('/api/leaves', [
            ...$this->payload(), 'save_as_draft' => true,
        ])->assertCreated()->json('leave.id');
        $this->putJson("/api/leaves/{$id}", [...$this->payload(), 'end_date' => '2026-09-09'])->assertOk();
        $this->assertSame(3.0, Leave::findOrFail($id)->total_days);
        $this->postJson("/api/leaves/{$id}/submit")->assertOk();
        $this->putJson("/api/leaves/{$id}", $this->payload())->assertUnprocessable();
        $this->assertSame(3.0, Leave::findOrFail($id)->total_days);
    }

    public function test_cross_fiscal_year_is_rejected_on_draft_update_submission_and_admin_creation(): void
    {
        $payload = [...$this->payload(), 'start_date' => '2026-09-30', 'end_date' => '2026-10-02'];
        $id = $this->actingAs($this->owner)->postJson('/api/leaves', [
            ...$this->payload(), 'save_as_draft' => true,
        ])->assertCreated()->json('leave.id');
        $this->postJson("/api/leaves/{$id}/draft", $payload)->assertUnprocessable();
        Leave::findOrFail($id)->update($payload);
        $this->postJson("/api/leaves/{$id}/submit")->assertUnprocessable();
        $this->assertSame('Draft', Leave::findOrFail($id)->status);
        $this->actingAs($this->manager)->postJson('/api/admin/leaves', [...$payload, 'user_id' => $this->owner->id])->assertUnprocessable();
    }

    public function test_admin_recording_respects_explicit_self_scope(): void
    {
        DB::table('permission_scopes')->insert([
            'model_type' => $this->manager->getMorphClass(), 'model_id' => $this->manager->id,
            'permission_name' => 'leave.management.manage', 'scope' => 'SELF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->manager)->postJson('/api/admin/leaves', [
            ...$this->payload(), 'user_id' => $this->owner->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('leaves', ['user_id' => $this->owner->id]);
    }

    public function test_explicit_deny_blocks_admin_recording_even_with_role_permission(): void
    {
        DB::table('permission_denies')->insert([
            'model_type' => $this->manager->getMorphClass(), 'model_id' => $this->manager->id,
            'permission_name' => 'leave.management.manage', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->manager)->postJson('/api/admin/leaves', [
            ...$this->payload(), 'user_id' => $this->owner->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('leaves', ['user_id' => $this->owner->id]);
    }
}
