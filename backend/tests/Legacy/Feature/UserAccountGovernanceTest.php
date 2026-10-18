<?php

namespace Tests\Legacy\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * การจัดการบัญชีผู้ใช้ตามแนวทางหน่วยงาน
 *
 * - ห้ามลบบัญชีจริง เพราะ cascade ลบประวัติการลา/ทะเบียนบุคลากรทิ้ง → ใช้การระงับแทน
 * - การเปลี่ยนบทบาท/สิทธิ์/ทะเบียนประวัติ/รหัสผ่านโดยผู้ดูแลต้องมี audit log
 *   (คอลัมน์ของ users เช่น is_active ถูก audit โดย model hook อยู่แล้ว)
 * - audit log ต้องไม่เก็บค่าข้อมูลอ่อนไหว (เลขบัตรประชาชน) หรือรหัสผ่าน
 * - ผู้ดูแลระงับบัญชีตนเองไม่ได้
 * - เปลี่ยนรหัสผ่านของตนเองต้องยืนยันรหัสผ่านเดิม
 */
class UserAccountGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $department;

    private Role $employeeRole;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        $this->employeeRole = Role::findOrCreate('Employee', 'web');
        $this->department = Department::create(['name' => 'ฝ่ายทดสอบ']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->admin = $this->admin->fresh();
    }

    private function staff(): User
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);
        $user->assignRole('Employee');
        $user->profile()->create(['first_name' => 'สมชาย', 'last_name' => 'ใจดี']);

        return $user->fresh();
    }

    public function test_deleting_a_user_is_refused_and_keeps_all_history(): void
    {
        $target = $this->staff();
        $this->assertDatabaseHas('personnel', ['user_id' => $target->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'ระบบไม่อนุญาตให้ลบบัญชีผู้ใช้ เพื่อรักษาประวัติการลา การลงเวลา และทะเบียนประวัติบุคลากร กรุณาใช้ "ระงับการใช้งาน" แทน']);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $target->id]);
        $this->assertDatabaseHas('personnel', ['user_id' => $target->id]);
    }

    public function test_user_without_delete_permission_still_gets_forbidden(): void
    {
        $target = $this->staff();
        $other = $this->staff();

        $this->actingAs($other)
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertForbidden();
    }

    public function test_deactivating_a_user_is_audited_and_blocks_further_api_access(): void
    {
        $target = $this->staff();
        $token = $target->createToken('auth_token')->plainTextToken;

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$target->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $log = AuditLog::where('action', 'user.updated')->where('resource_id', (string) $target->id)->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertTrue($log->before['is_active']);
        $this->assertFalse($log->after['is_active']);

        // token เดิมถูกยกเลิกทันที ไม่ต้องรอหมดอายุ
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->admin->id}/toggle-status")
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_creating_a_user_is_audited_without_sensitive_values(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'ผู้ใช้ใหม่',
            'email' => 'new-user@example.com',
            'password' => 'a-very-long-password',
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'profile' => ['first_name' => 'ใหม่', 'last_name' => 'ทดสอบ', 'id_card_number' => '1234567890123'],
        ])->assertCreated();

        $created = User::where('email', 'new-user@example.com')->firstOrFail();
        $access = AuditLog::where('action', 'user.access_changed')->where('resource_id', (string) $created->id)->firstOrFail();
        $this->assertSame($this->admin->id, $access->actor_id);
        $this->assertNull($access->before);
        $this->assertSame(['Employee'], $access->after['roles']);

        $profile = AuditLog::where('action', 'user.profile_updated')->where('resource_id', (string) $created->id)->firstOrFail();
        $this->assertContains('id_card_number', $profile->after['fields']);

        $this->assertAuditLogsDoNotContain('1234567890123');
        $this->assertAuditLogsDoNotContain('a-very-long-password');
    }

    public function test_changing_role_and_department_is_audited_with_before_and_after(): void
    {
        $target = $this->staff();
        $newDepartment = Department::create(['name' => 'ฝ่ายใหม่']);
        $hrRole = Role::findOrCreate('HR', 'web');

        $this->actingAs($this->admin)->putJson("/api/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $hrRole->id,
            'department_id' => $newDepartment->id,
            'password' => 'another-long-password',
            'profile' => ['id_card_number' => '9876543210987'],
        ])->assertOk();

        $access = AuditLog::where('action', 'user.access_changed')->where('resource_id', (string) $target->id)->firstOrFail();
        $this->assertSame($this->admin->id, $access->actor_id);
        $this->assertSame(['Employee'], $access->before['roles']);
        $this->assertSame(['HR'], $access->after['roles']);

        $department = AuditLog::where('action', 'user.updated')->where('resource_id', (string) $target->id)->latest('id')->firstOrFail();
        $this->assertSame($this->department->id, $department->before['department_id']);
        $this->assertSame($newDepartment->id, $department->after['department_id']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_by_admin', 'resource_id' => (string) $target->id, 'actor_id' => $this->admin->id]);

        $profile = AuditLog::where('action', 'user.profile_updated')->where('resource_id', (string) $target->id)->firstOrFail();
        $this->assertSame(['id_card_number'], $profile->after['fields']);

        $this->assertAuditLogsDoNotContain('9876543210987');
        $this->assertAuditLogsDoNotContain('another-long-password');
    }

    public function test_saving_without_any_change_does_not_write_an_audit_entry(): void
    {
        $target = $this->staff();

        $this->actingAs($this->admin)->putJson("/api/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
        ])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.access_changed']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.profile_updated']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.password_reset_by_admin']);
    }

    public function test_changing_own_password_requires_the_current_password(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->postJson('/api/profile', [
            'password' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->postJson('/api/profile', [
            'password' => 'brand-new-password',
            'current_password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user)->postJson('/api/profile', [
            'password' => 'brand-new-password',
            'current_password' => 'password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_profile_update_without_password_change_does_not_need_current_password(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->postJson('/api/profile', [
            'phone' => '0812345678',
        ])->assertOk();
    }

    private function assertAuditLogsDoNotContain(string $needle): void
    {
        $raw = json_encode(AuditLog::all()->map->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($needle, $raw);
    }
}
