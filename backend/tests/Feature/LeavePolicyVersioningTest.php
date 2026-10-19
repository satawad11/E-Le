<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Leave;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * แก้นโยบายการลาจากหน้าจอต้องไม่เขียนทับรุ่นที่ใบลาใช้อยู่ (PROJECT_RULES ข้อ 7 · REDESIGN-2569)
 *
 * เจ้าของระบบจะกรอกจำนวนวันลาคลอด/ลาเลี้ยงดูบุตรเองภายหลัง — ใบลาเดิมต้องยังใช้ค่าเดิม
 */
class LeavePolicyVersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('leave.policy.manage', 'web'));

        $type = PersonnelType::create(['code' => 'versioning_test', 'name' => 'ประเภททดสอบรุ่น', 'is_active' => true]);
        $leaveType = LeaveType::create(['name' => 'ลาคลอดทดสอบรุ่น', 'max_days' => 90]);
        $this->policy = LeavePolicy::create([
            'personnel_type_id' => $type->id, 'leave_type_id' => $leaveType->id, 'effective_from' => '2025-10-01',
            'annual_quota' => 90, 'exclude_holidays' => false, 'period_basis' => 'fiscal_year', 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'personnel_type_id' => $this->policy->personnel_type_id, 'leave_type_id' => $this->policy->leave_type_id,
            'effective_from' => '2025-10-01', 'annual_quota' => 90, 'exclude_holidays' => false,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ], $overrides);
    }

    private function useInLeave(): Leave
    {
        return Leave::create([
            'user_id' => User::factory()->create()->id, 'leave_type_id' => $this->policy->leave_type_id,
            'leave_policy_id' => $this->policy->id, 'start_date' => '2026-01-05', 'end_date' => '2026-04-04',
            'total_days' => 90, 'reason' => 'คลอดบุตร', 'status' => 'Approved',
        ]);
    }

    public function test_a_policy_used_by_a_leave_gets_a_new_version(): void
    {
        $leave = $this->useInLeave();

        $res = $this->actingAs($this->admin)
            ->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['annual_quota' => 98]))
            ->assertOk()->assertJsonPath('versioned', true);

        $this->assertStringContainsString('สร้างรุ่นใหม่', $res->json('message'));
        $old = $this->policy->fresh();
        $this->assertSame(90.0, (float) $old->annual_quota, 'รุ่นเดิมต้องไม่ถูกเขียนทับ');
        $this->assertSame(today()->subDay()->toDateString(), $old->effective_to->toDateString());

        $new = LeavePolicy::findOrFail($res->json('policy.id'));
        $this->assertSame(98.0, (float) $new->annual_quota);
        $this->assertSame(today()->toDateString(), $new->effective_from->toDateString());
        $this->assertSame($old->id, $leave->fresh()->leave_policy_id, 'ใบลาเดิมยังผูกรุ่นเดิม');
        $this->assertTrue(AuditLog::where('action', 'leave_policy.versioned')->exists());
    }

    public function test_a_future_effective_date_is_kept_for_the_new_version(): void
    {
        $this->useInLeave();
        $from = today()->addMonth()->toDateString();

        $res = $this->actingAs($this->admin)
            ->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['annual_quota' => 98, 'effective_from' => $from]))
            ->assertOk();

        $this->assertSame($from, LeavePolicy::find($res->json('policy.id'))->effective_from->toDateString());
        $this->assertSame(today()->addMonth()->subDay()->toDateString(), $this->policy->fresh()->effective_to->toDateString());
    }

    public function test_an_unused_policy_is_edited_in_place(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['annual_quota' => 98]))
            ->assertOk()->assertJsonPath('versioned', false);

        $this->assertSame(98.0, (float) $this->policy->fresh()->annual_quota);
        $this->assertSame(1, LeavePolicy::where('leave_type_id', $this->policy->leave_type_id)->count());
        $this->assertTrue(AuditLog::where('action', 'leave_policy.updated')->exists());
    }

    public function test_closing_a_used_policy_does_not_create_a_version(): void
    {
        $this->useInLeave();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['effective_to' => '2026-09-30']))
            ->assertOk()->assertJsonPath('versioned', false);

        $this->assertSame('2026-09-30', $this->policy->fresh()->effective_to->toDateString());
        $this->assertSame(1, LeavePolicy::where('leave_type_id', $this->policy->leave_type_id)->count());
    }

    public function test_only_policy_managers_can_edit(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson("/api/admin/leave-policies/{$this->policy->id}", $this->payload(['annual_quota' => 120]))
            ->assertForbidden();

        $this->assertSame(90.0, (float) $this->policy->fresh()->annual_quota);
    }
}
