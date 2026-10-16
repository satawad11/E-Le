<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * การลบใบลาแยกเป็นสิทธิ์ `leave.delete` ของ Super Admin เท่านั้น (เจ้าของระบบตัดสิน 2026-10-16)
 *
 * เดิมผูกกับ `leave.management.manage` — เมื่อมอบสิทธิ์นั้นให้ HR Head งานบุคลากรจึงลบใบลา
 * ที่อนุมัติแล้วของใครก็ได้ ขัดกฎ "ไม่ลบประวัติสำคัญ"
 */
class LeaveDeletePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['department_id' => Department::create(['name' => 'ฝ่ายทดสอบลบใบลา'])->id]);
        $this->type = LeaveType::create(['name' => 'ลาทดสอบการลบ', 'max_days' => 30]);
    }

    private function leave(string $status): Leave
    {
        return Leave::create([
            'user_id' => $this->owner->id, 'leave_type_id' => $this->type->id,
            'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->toDateString(),
            'total_days' => 1, 'reason' => 'ทดสอบการลบ', 'status' => $status,
        ]);
    }

    public function test_leave_management_alone_no_longer_deletes_other_peoples_leave(): void
    {
        Permission::findOrCreate('leave.management.manage', 'web');
        $hr = User::factory()->create();
        $hr->givePermissionTo('leave.management.manage');
        $leave = $this->leave('Approved');

        $this->actingAs($hr)->deleteJson("/api/leaves/{$leave->id}")->assertForbidden();

        $this->assertNull($leave->fresh()->deleted_at);
    }

    public function test_the_delete_permission_soft_deletes_and_audits(): void
    {
        Permission::findOrCreate('leave.delete', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('leave.delete');
        $leave = $this->leave('Approved');

        $this->actingAs($admin)->deleteJson("/api/leaves/{$leave->id}")->assertOk();

        $this->assertSoftDeleted('leaves', ['id' => $leave->id]);
        $log = AuditLog::where('action', 'leave.deleted')->where('resource_id', (string) $leave->id)->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Approved', $log->before['status']);
    }

    public function test_owner_still_deletes_their_own_pending_leave_within_the_allowed_days(): void
    {
        $pending = $this->leave('Pending');
        $approved = $this->leave('Approved');

        $this->actingAs($this->owner)->deleteJson("/api/leaves/{$approved->id}")->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/api/leaves/{$pending->id}")->assertOk();

        $this->assertSoftDeleted('leaves', ['id' => $pending->id]);
    }

    public function test_only_super_admin_holds_the_delete_permission_after_seeding_and_migration(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        (require database_path('migrations/2026_10_17_120000_create_leave_delete_permission.php'))->up();

        $this->assertTrue(Role::findByName('Super Admin', 'web')->hasPermissionTo('leave.delete'));
        foreach (['HR Head', 'Director', 'Deputy Director', 'Employee'] as $name) {
            $this->assertFalse(Role::findByName($name, 'web')->hasPermissionTo('leave.delete'), "{$name} ต้องไม่มีสิทธิ์ลบใบลา");
        }
    }
}
