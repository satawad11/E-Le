<?php

namespace Tests\Legacy\Feature;

use App\Models\User;
use App\Services\PermissionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-1 / 04-2 / 04-5
 *
 * ครอบสามเรื่อง:
 *  1. ชื่อ permission เก่ากับ canonical ต้องถือว่าเทียบเท่ากัน
 *     (ฐานข้อมูลจริงถือชื่อเก่า แต่โค้ดเช็คชื่อ canonical)
 *  2. explicit deny ต้องชนะ allow และชนะแม้กระทั่ง Super Admin
 *  3. ปิด feature flag แล้วต้องกลับไปพฤติกรรมเดิมได้
 */
class PermissionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private PermissionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(PermissionEvaluator::class);
    }

    private function userWithLegacyPermission(string $legacy): User
    {
        Permission::findOrCreate($legacy, 'web');

        $user = User::factory()->create();
        $user->givePermissionTo($legacy);

        return $user->fresh();
    }

    // ------------------------------------------------ 04-1 การเทียบชื่อข้ามรุ่น

    public function test_legacy_permission_satisfies_the_canonical_name(): void
    {
        // ผู้ใช้ถือ view_users ตามที่ฐานข้อมูลจริงเป็น
        $user = $this->userWithLegacyPermission('view_users');

        // แต่โค้ดเช็คด้วยชื่อ canonical
        $this->assertTrue($this->evaluator->allows($user, 'system.user.view'));
    }

    public function test_canonical_permission_also_satisfies_the_legacy_name(): void
    {
        Permission::findOrCreate('system.user.view', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('system.user.view');

        $this->assertTrue($this->evaluator->allows($user->fresh(), 'view_users'));
    }

    public function test_unrelated_permission_is_not_granted(): void
    {
        $user = $this->userWithLegacyPermission('view_users');

        $this->assertFalse($this->evaluator->allows($user, 'leave.report.view'));
        $this->assertFalse($this->evaluator->allows($user, 'attendance.ot_summary'));
    }

    public function test_gate_honours_the_alias_mapping(): void
    {
        $user = $this->userWithLegacyPermission('view_users');

        $this->assertTrue($user->can('system.user.view'));
    }

    /**
     * เดิมไม่มี role ใดถือ attendance.ot_summary จึงต้องไม่มีการ map มาให้
     * มิฉะนั้นจะเป็นการให้สิทธิ์ใหม่โดยไม่ตั้งใจ
     */
    public function test_ot_summary_is_not_aliased_from_any_legacy_permission(): void
    {
        $aliased = DB::table('permission_aliases')->where('canonical_name', 'attendance.ot_summary')->exists();

        $this->assertFalse($aliased, 'attendance.ot_summary ต้องไม่ถูก map จากชื่อเก่าใด ๆ');
    }

    // ------------------------------------------------------- 04-2 explicit deny

    public function test_explicit_deny_beats_an_allow(): void
    {
        $user = $this->userWithLegacyPermission('view_users');
        $this->assertTrue($user->can('system.user.view'));

        $this->deny($user, 'system.user.view');

        $this->assertFalse($this->evaluator->allows($user->fresh(), 'system.user.view'));
    }

    public function test_deny_cannot_be_bypassed_by_using_the_other_name(): void
    {
        $user = $this->userWithLegacyPermission('view_users');
        $this->deny($user, 'system.user.view');

        // เรียกด้วยชื่อเก่าก็ต้องยังถูกปฏิเสธ
        $this->assertFalse($this->evaluator->allows($user->fresh(), 'view_users'));
    }

    // ------------------------------------------------- 04-5 deny ชนะ Super Admin

    public function test_explicit_deny_beats_super_admin(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        Permission::findOrCreate('system.user.view', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->assertTrue($admin->fresh()->can('system.user.view'));

        $this->deny($admin, 'system.user.view');

        $this->assertFalse($admin->fresh()->can('system.user.view'));
    }

    public function test_super_admin_keeps_every_other_ability_when_denied_one(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        Permission::findOrCreate('system.user.view', 'web');
        Permission::findOrCreate('leave.report.view', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->deny($admin, 'system.user.view');

        $this->assertFalse($admin->fresh()->can('system.user.view'));
        $this->assertTrue($admin->fresh()->can('leave.report.view'));
    }

    // ------------------------------------------------------------ feature flag

    public function test_disabling_the_flag_restores_the_previous_behaviour(): void
    {
        config(['authorization.use_new_engine' => false]);

        $user = $this->userWithLegacyPermission('view_users');

        // พฤติกรรมเดิม: ชื่อ canonical ไม่มีในฐานข้อมูลจึงถูกปฏิเสธ
        $this->assertFalse($user->can('system.user.view'));
        // แต่ชื่อเก่าที่ถืออยู่ยังใช้ได้
        $this->assertTrue($user->can('view_users'));
    }

    private function deny(User $user, string $ability): void
    {
        DB::table('permission_denies')->insert([
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'permission_name' => $ability,
            'reason' => 'ทดสอบ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
