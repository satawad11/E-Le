<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ย้ายศูนย์ปฏิบัติการออกจาก `saraban.*` ต้องไม่ทำให้ใครได้หรือเสียสิทธิ์
 */
class OperationsPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_01_100000_move_operations_permissions_to_system.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** RefreshDatabase รัน migration ไปแล้ว — คืนสภาพก่อนย้ายเพื่อทดสอบการย้ายจริง */
    private function legacy(string $name): Permission
    {
        $new = str_replace('saraban.', 'system.', $name);
        DB::table('permissions')->where('name', $new)->delete();

        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    public function test_roles_and_direct_grants_keep_the_same_access_under_the_new_name(): void
    {
        $role = Role::create(['name' => 'ผู้ดูแลปฏิบัติการ', 'guard_name' => 'web']);
        $role->givePermissionTo($this->legacy('saraban.operations.view'));
        $direct = User::factory()->create();
        $direct->givePermissionTo($this->legacy('saraban.feature_flags.manage'));
        $viaRole = User::factory()->create();
        $viaRole->assignRole($role);

        $this->runMigration();

        $this->assertTrue($viaRole->fresh()->hasPermissionTo('system.operations.view'));
        $this->assertTrue($direct->fresh()->hasPermissionTo('system.feature_flags.manage'));
        $this->assertFalse(Permission::where('name', 'saraban.operations.view')->exists());
        $this->assertFalse($viaRole->fresh()->hasPermissionTo('system.feature_flags.manage'), 'ต้องไม่มีใครได้สิทธิ์เพิ่ม');
    }

    public function test_grants_are_merged_when_both_names_already_exist(): void
    {
        $role = Role::create(['name' => 'ผู้ตามแก้', 'guard_name' => 'web']);
        $role->givePermissionTo($this->legacy('saraban.exception.manage'));
        Permission::firstOrCreate(['name' => 'system.exception.manage', 'guard_name' => 'web']);

        $this->runMigration();

        $this->assertTrue($role->fresh()->hasPermissionTo('system.exception.manage'));
        $this->assertSame(1, Permission::where('name', 'system.exception.manage')->count());
        $this->assertFalse(Permission::where('name', 'saraban.exception.manage')->exists());
    }

    public function test_the_webhook_flag_setting_moves_with_its_new_key(): void
    {
        DB::table('feature_flags')->where('key', 'system.webhooks')->delete();
        DB::table('feature_flags')->insert([
            'uuid' => (string) Str::uuid(), 'key' => 'saraban.webhooks', 'name' => 'webhook',
            'module' => 'saraban', 'enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertDatabaseHas('feature_flags', ['key' => 'system.webhooks', 'enabled' => false]);
        $this->assertDatabaseMissing('feature_flags', ['key' => 'saraban.webhooks']);
    }
}
