<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-4 (4/4) — ล็อกชุดผู้มีสิทธิ์ยกเลิกใบลาของผู้อื่น
 *
 * เดิมชุดนี้คือ {Super Admin, HR Head} โดย HR Head ผ่านด้วย **ชื่อ role เท่านั้น**
 * ต่อมาใช้ system.user.view แทนชื่อ role ซึ่งพ่วงสิทธิ์ยกเลิกใบลาให้ทุกคนที่ดูรายชื่อผู้ใช้ได้
 * ปัจจุบันใช้สิทธิ์เฉพาะ `leave.request.cancel_others` โดย migration มอบให้ role ที่เคยผ่าน
 * HR Head จึงไม่เสียสิทธิ์ และเทสต์ชุดนี้กันไม่ให้บทบาทอื่นได้สิทธิ์เพิ่ม
 */
class LeaveCancellationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Leave $othersLeave;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'leave.management.manage', 'system.user.view', 'leave.request.cancel_others',
            'leave.report.view', 'leave.approval.dept', 'attendance.view_all',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $department = Department::create(['name' => 'ฝ่ายทดสอบ']);
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);
        $owner = User::factory()->create(['department_id' => $department->id]);

        $this->othersLeave = Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'total_days' => 2,
            'reason' => 'ของคนอื่น',
            'status' => 'Pending',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function cancel(User $user): int
    {
        return $this->actingAs($user)
            ->postJson("/api/leaves/{$this->othersLeave->id}/cancel")
            ->status();
    }

    public function test_cancel_others_permission_can_cancel_someone_elses_leave(): void
    {
        $this->assertNotSame(403, $this->cancel($this->userWith(['leave.request.cancel_others'])));
    }

    /** สิทธิ์ดูรายชื่อผู้ใช้อย่างเดียวต้องไม่พ่วงสิทธิ์ยกเลิกใบลาของผู้อื่น */
    public function test_user_view_permission_alone_does_not_grant_cancelling_others_leave(): void
    {
        $this->assertSame(403, $this->cancel($this->userWith(['system.user.view'])));
        $this->assertSame('Pending', $this->othersLeave->fresh()->status);
    }

    /** role ที่เคยยกเลิกได้ผ่าน view_users (HR Head) ต้องได้สิทธิ์ใหม่จาก migration — ไม่เสียสิทธิ์ */
    public function test_migration_grants_cancel_others_to_roles_that_could_cancel_before(): void
    {
        Permission::findOrCreate('view_users', 'web');
        $hrHead = Role::create(['name' => 'HR Head ทดสอบ', 'guard_name' => 'web']);
        $hrHead->givePermissionTo('view_users');
        $plain = Role::create(['name' => 'บุคลากรทดสอบ', 'guard_name' => 'web']);
        $plain->givePermissionTo('leave.report.view');

        (require database_path('migrations/2026_10_14_100000_add_leave_cancel_others_permission.php'))->up();

        $this->assertTrue($hrHead->fresh()->hasPermissionTo('leave.request.cancel_others'));
        $this->assertFalse($plain->fresh()->hasPermissionTo('leave.request.cancel_others'));

        $user = User::factory()->create();
        $user->assignRole($hrHead);
        $this->assertNotSame(403, $this->cancel($user->fresh()));
    }

    public function test_leave_manager_can_cancel_someone_elses_leave(): void
    {
        $this->assertNotSame(403, $this->cancel($this->userWith(['leave.management.manage'])));
    }

    /**
     * Director / Deputy ถือ leave.report.view + leave.approval.dept + attendance.view_all
     * แต่ไม่ได้ถือ system.user.view จึงต้องยกเลิกใบลาของผู้อื่นไม่ได้ เหมือนเดิม
     */
    public function test_director_level_permissions_do_not_grant_cancelling_others_leave(): void
    {
        $viewer = $this->userWith(['leave.report.view', 'leave.approval.dept', 'attendance.view_all']);

        $this->assertSame(403, $this->cancel($viewer));
    }

    public function test_ordinary_user_cannot_cancel_someone_elses_leave(): void
    {
        $this->assertSame(403, $this->cancel($this->userWith([])));
    }
}
