<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-6 — สิทธิ์รายบุคคลต้องมีแหล่งเดียวคือ Spatie
 *
 * เดิม `Admin\UserController` เรียก `syncPermissions()` (ถูกต้อง) แล้วยัง
 * เขียนซ้ำลงคอลัมน์ `users.custom_permissions` ด้วย ซึ่งเป็นแหล่งข้อมูลที่สอง
 * ตาม DB-RISK-009
 *
 * คอลัมน์นั้นถูกลบไปแล้วใน LEGACY-03 แต่โค้ดยังเขียนอยู่ ทำให้การกำหนดสิทธิ์
 * รายบุคคลผ่านหน้าจัดการผู้ใช้ **พังจริง** (หน้าเว็บส่ง custom_permissions
 * มาทุกครั้ง) — เทสต์ชุดนี้พิสูจน์และล็อกไว้
 */
class UserDirectPermissionTest extends TestCase
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
        Permission::findOrCreate('leave.report.view', 'web');
        Permission::findOrCreate('attendance.view_all', 'web');

        $this->department = Department::create(['name' => 'ฝ่ายทดสอบ']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->admin = $this->admin->fresh();
    }

    public function test_the_dead_custom_permissions_column_is_really_gone(): void
    {
        $this->assertFalse(
            Schema::hasColumn('users', 'custom_permissions'),
            'คอลัมน์ถูกลบไปแล้วใน LEGACY-03 — โค้ดต้องไม่เขียนลงไปอีก',
        );
    }

    public function test_admin_can_assign_individual_permissions_when_creating_a_user(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'ผู้ใช้ทดสอบ',
            'email' => 'newuser@example.com',
            'password' => 'a-very-long-password',
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'custom_permissions' => json_encode(['leave.report.view']),
        ]);

        $response->assertSuccessful();

        $created = User::where('email', 'newuser@example.com')->firstOrFail();

        $this->assertTrue(
            $created->hasDirectPermission('leave.report.view'),
            'สิทธิ์รายบุคคลต้องถูกบันทึกลง Spatie',
        );
    }

    public function test_admin_can_update_individual_permissions_of_an_existing_user(): void
    {
        $target = User::factory()->create(['department_id' => $this->department->id]);
        $target->assignRole('Employee');

        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'custom_permissions' => json_encode(['attendance.view_all']),
        ]);

        $response->assertSuccessful();

        $this->assertTrue($target->fresh()->hasDirectPermission('attendance.view_all'));
    }

    public function test_clearing_individual_permissions_works(): void
    {
        $target = User::factory()->create(['department_id' => $this->department->id]);
        $target->assignRole('Employee');
        $target->givePermissionTo('attendance.view_all');

        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'custom_permissions' => json_encode([]),
        ]);

        $response->assertSuccessful();

        $this->assertFalse($target->fresh()->hasDirectPermission('attendance.view_all'));
    }

    /**
     * หน้าจัดการผู้ใช้ตั้งค่า checkbox สิทธิ์รายบุคคลจาก `permissions` ในรายการผู้ใช้
     * แล้วส่งกลับมาทุกครั้งที่บันทึก เดิมรายการไม่มี `permissions` ฟอร์มจึงส่ง []
     * และการบันทึกครั้งใดก็ตามของผู้ดูแลระบบลบสิทธิ์รายบุคคลของผู้ใช้นั้นทิ้งเงียบ ๆ
     */
    public function test_saving_a_user_from_the_list_keeps_their_individual_permissions(): void
    {
        $target = User::factory()->create(['department_id' => $this->department->id]);
        $target->assignRole('Employee');
        $target->givePermissionTo('attendance.view_all');

        $listed = collect($this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->json())
            ->firstWhere('id', $target->id);

        // เหมือน openEditModal() ใน UsersManagementView.vue
        $formPermissions = collect($listed['permissions'] ?? [])
            ->map(fn ($permission) => is_string($permission) ? $permission : $permission['name'])
            ->all();

        $this->actingAs($this->admin)->putJson("/api/admin/users/{$target->id}", [
            'name' => 'ชื่อใหม่',
            'email' => $listed['email'],
            'role_id' => $listed['role_id'],
            'department_id' => $listed['department_id'],
            'custom_permissions' => json_encode($formPermissions),
        ])->assertSuccessful();

        $this->assertTrue(
            $target->fresh()->hasDirectPermission('attendance.view_all'),
            'บันทึกฟอร์มโดยไม่แตะแท็บสิทธิ์ ต้องไม่ลบสิทธิ์รายบุคคล',
        );
    }

    /** รายการต้องมีเฉพาะสิทธิ์รายบุคคล ไม่ปนสิทธิ์จากบทบาท ไม่งั้นบันทึกแล้วสิทธิ์ของบทบาทจะกลายเป็นสิทธิ์รายบุคคล */
    public function test_user_list_shows_only_direct_permissions(): void
    {
        $this->employeeRole->givePermissionTo('leave.report.view');
        $target = User::factory()->create(['department_id' => $this->department->id]);
        $target->assignRole('Employee');
        $target->givePermissionTo('attendance.view_all');

        $listed = collect($this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->json())
            ->firstWhere('id', $target->id);

        $this->assertSame(['attendance.view_all'], array_column($listed['permissions'], 'name'));
    }
}
