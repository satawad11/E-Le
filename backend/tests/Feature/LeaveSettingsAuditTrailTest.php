<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * การตั้งค่าที่กำหนดสิทธิการลาต้องตรวจย้อนได้ว่าใครเปลี่ยนอะไรเมื่อใด
 *
 * เดิม CRUD ของประเภทการลา ปีงบประมาณ และนโยบายสิทธิ ไม่มีการบันทึกประวัติเลย
 * ต่างจากการตั้งค่าระบบและศูนย์จัดการเอกสารที่บันทึกครบ — การเปลี่ยนเพดานวันลา
 * หรือรอบปีงบประมาณจึงเป็นการเปลี่ยนสิทธิของคนทั้งองค์กรโดยไม่มีร่องรอย
 */
class LeaveSettingsAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $abilities): User
    {
        $admin = User::factory()->create(['is_active' => true]);

        foreach ($abilities as $ability) {
            $admin->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }

        return $admin;
    }

    public function test_creating_and_editing_a_leave_type_is_recorded(): void
    {
        $admin = $this->admin(['manage_leave_types']);

        $created = $this->actingAs($admin)->postJson('/api/admin/leave-types', [
            'name' => 'ลาทดสอบประวัติ', 'code_prefix' => 'AUD', 'max_days' => 10,
        ])->assertCreated()->json('leave_type');

        $this->actingAs($admin)->putJson('/api/admin/leave-types/'.$created['id'], [
            'name' => 'ลาทดสอบประวัติ (แก้ไข)', 'code_prefix' => 'AUD', 'max_days' => 20,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_type_created', 'resource_id' => (string) $created['id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_type_updated', 'resource_id' => (string) $created['id']]);
    }

    public function test_deleting_a_leave_type_keeps_what_it_was_before(): void
    {
        $admin = $this->admin(['manage_leave_types']);
        $type = LeaveType::create(['name' => 'ลาที่จะลบ', 'code_prefix' => 'DEL']);

        $this->actingAs($admin)->deleteJson('/api/admin/leave-types/'.$type->id)->assertOk();

        $log = AuditLog::where('action', 'leave_type_deleted')->firstOrFail();

        $this->assertSame('ลาที่จะลบ', $log->before['name'] ?? null, 'ต้องเก็บค่าก่อนลบไว้ให้ตรวจย้อนได้');
    }

    public function test_fiscal_year_changes_are_recorded(): void
    {
        $admin = $this->admin(['system.setting.manage']);

        $year = $this->actingAs($admin)->postJson('/api/admin/fiscal-years', [
            'name' => 'ปีงบประมาณ 2570', 'year' => 2570,
            'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_current' => false,
        ])->assertCreated()->json('fiscal_year');

        $this->actingAs($admin)->putJson('/api/admin/fiscal-years/'.$year['id'], [
            'name' => 'ปีงบประมาณ 2570 (แก้ไข)', 'year' => 2570,
            'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'is_current' => false,
        ])->assertOk();

        $this->actingAs($admin)->deleteJson('/api/admin/fiscal-years/'.$year['id'])->assertOk();

        foreach (['fiscal_year_created', 'fiscal_year_updated', 'fiscal_year_deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'resource_id' => (string) $year['id']]);
        }
    }

    public function test_policy_changes_are_recorded_including_versioning(): void
    {
        $admin = $this->admin(['leave.policy.manage']);
        $personnel = PersonnelType::create(['code' => 'audit_trail', 'name' => 'บุคลากรทดสอบประวัติ']);
        $type = LeaveType::create(['name' => 'ลาทดสอบนโยบาย', 'code_prefix' => 'POL']);

        $policy = $this->actingAs($admin)->postJson('/api/admin/leave-policies', [
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 10,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ])->assertCreated()->json('policy');

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_policy_created', 'resource_id' => (string) $policy['id']]);

        $this->actingAs($admin)->putJson('/api/admin/leave-policies/'.$policy['id'], [
            'personnel_type_id' => $personnel->id, 'leave_type_id' => $type->id,
            'effective_from' => '2025-10-01', 'annual_quota' => 15,
            'period_basis' => 'fiscal_year', 'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_policy_updated', 'resource_id' => (string) $policy['id']]);
        $this->assertSame(15.0, (float) LeavePolicy::find($policy['id'])->annual_quota);
    }

    public function test_a_fiscal_year_in_use_cannot_be_deleted_and_the_current_one_is_protected(): void
    {
        $admin = $this->admin(['system.setting.manage']);

        $current = FiscalYear::create([
            'name' => 'ปีปัจจุบัน', 'year' => 2569,
            'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'is_current' => true,
        ]);

        $this->actingAs($admin)->deleteJson('/api/admin/fiscal-years/'.$current->id)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ปีงบประมาณปัจจุบัน'));

        $this->assertDatabaseHas('fiscal_years', ['id' => $current->id]);
    }
}
