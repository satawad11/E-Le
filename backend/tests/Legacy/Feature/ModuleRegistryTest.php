<?php

namespace Tests\Legacy\Feature;

use App\Models\Menu;
use App\Models\User;
use App\Services\MenuService;
use App\Services\Modules\ModuleManifestException;
use App\Services\Modules\ModuleRegistry;
use App\Services\NotificationCenter\NotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_modules_declare_the_complete_extension_contract(): void
    {
        $registry = app(ModuleRegistry::class);
        $this->assertSame(
            ['system', 'leave', 'attendance', 'overtime', 'saraban', 'notification', 'reporting'],
            array_keys($registry->all()),
        );

        foreach ($registry->all() as $module) {
            $manifest = $module->toArray();
            foreach (['code', 'name_th', 'routes', 'menus', 'permissions', 'policies', 'workflow_definitions', 'settings', 'notifications', 'reports', 'audit_events', 'version', 'enabled', 'dependencies'] as $field) {
                $this->assertArrayHasKey($field, $manifest, "{$module->code()}:{$field}");
            }
        }

        foreach (['finance', 'procurement', 'asset', 'planning_budget', 'vehicle_booking', 'room_booking', 'maintenance', 'meeting'] as $future) {
            $this->assertFalse($registry->has($future), "LEGACY-12 must not invent {$future} business logic.");
        }
    }

    public function test_new_module_permissions_must_follow_module_resource_action(): void
    {
        $valid = $this->manifest('finance', ['finance.invoice.view' => 'ดูใบสำคัญ']);
        $this->assertSame('finance', (new ModuleRegistry(['finance' => $valid]))->get('finance')->code());

        $this->expectException(ModuleManifestException::class);
        new ModuleRegistry(['finance' => $this->manifest('finance', ['finance.view' => 'invalid'])]);
    }

    public function test_legacy_permission_exception_must_be_declared_by_exact_name(): void
    {
        $manifest = $this->manifest('finance', ['finance.view' => 'legacy']);
        $manifest['legacy_permissions'] = ['finance.view'];

        $this->assertSame(['finance.view' => 'legacy'], (new ModuleRegistry(['finance' => $manifest]))->get('finance')->permissions());
    }

    public function test_unknown_and_circular_dependencies_are_rejected(): void
    {
        try {
            new ModuleRegistry(['finance' => $this->manifest('finance', [], ['missing'])]);
            $this->fail('Unknown dependency was accepted.');
        } catch (ModuleManifestException $exception) {
            $this->assertStringContainsString('unknown module', $exception->getMessage());
        }

        $this->expectException(ModuleManifestException::class);
        new ModuleRegistry([
            'finance' => $this->manifest('finance', [], ['asset']),
            'asset' => $this->manifest('asset', [], ['finance']),
        ]);
    }

    public function test_disabling_a_module_hides_its_menu_and_returns_not_found_for_its_routes(): void
    {
        $user = User::factory()->create();
        Menu::create([
            'code' => 'leave-root', 'title_th' => 'การลา', 'module' => 'leave',
            'route_path' => '/leaves', 'sort_order' => 1, 'is_active' => true,
        ]);
        config(['modules.leave.enabled' => false]);
        Cache::flush();

        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('leave'));
        $this->assertSame([], app(MenuService::class)->getUserMenuTree($user));
        $this->actingAs($user)->getJson('/api/leaves')->assertNotFound();
    }

    public function test_disabling_a_dependency_disables_dependants_transitively(): void
    {
        config(['modules.attendance.enabled' => false, 'modules.overtime.enabled' => true]);

        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('attendance'));
        $this->assertFalse(app(ModuleRegistry::class)->isEnabled('overtime'));
        $this->actingAs(User::factory()->create())->getJson('/api/overtime/orders')->assertNotFound();
    }

    public function test_core_is_not_a_runtime_feature_switch(): void
    {
        config(['modules.admin.enabled' => false]);

        $this->assertFalse(app(ModuleRegistry::class)->get('system')->canDisable());
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('system'));
    }

    public function test_disabling_notification_closes_all_routes_and_stops_new_emission(): void
    {
        config(['modules.notification.enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/notifications')->assertNotFound();
        $this->actingAs($user)->getJson('/api/notification-preferences')->assertNotFound();
        $this->assertNull(app(NotificationCenter::class)->emit('leave.approved', [$user]));
        $this->assertDatabaseCount('notification_events', 0);
    }

    public function test_menu_visibility_comes_from_permission_but_never_authorizes_backend(): void
    {
        $permission = Permission::findOrCreate('saraban.document.view', 'web');
        $menu = Menu::create([
            'code' => 'users', 'title_th' => 'ผู้ใช้งาน', 'module' => 'system',
            'route_path' => '/admin/users', 'sort_order' => 1, 'is_active' => true,
        ]);
        $menu->permissions()->attach($permission);
        $user = User::factory()->create();

        $this->assertSame([], app(MenuService::class)->getUserMenuTree($user));
        $user->givePermissionTo('saraban.document.view');
        Cache::flush();
        $this->assertSame('users', app(MenuService::class)->getUserMenuTree($user)[0]['code']);

        // This endpoint requires view_users. Seeing its menu through a different
        // permission cannot grant access to the backend operation.
        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_core_registry_and_engine_do_not_import_business_modules(): void
    {
        foreach ([
            app_path('Contracts/Modules/ModuleContract.php'),
            app_path('Services/Modules/ModuleDefinition.php'),
            app_path('Services/Modules/ModuleRegistry.php'),
            app_path('Http/Middleware/EnsureModuleEnabled.php'),
            app_path('Providers/AppServiceProvider.php'),
            app_path('Providers/ModuleServiceProvider.php'),
            app_path('Services/Workflow/WorkflowEngine.php'),
        ] as $path) {
            $contents = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/App\\\\(?:Models|Services)\\\\(?:Leave|Attendance|Overtime|Saraban)/', $contents, $path);
        }
    }

    public function test_admin_module_endpoint_exposes_registry_metadata(): void
    {
        Permission::findOrCreate('admin.users.manage_permissions', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('admin.users.manage_permissions');

        $this->actingAs($admin)->getJson('/api/admin/permissions/modules')
            ->assertOk()
            ->assertJsonPath('modules.leave.code', 'leave')
            ->assertJsonPath('modules.leave.name_th', 'ระบบการลา')
            ->assertJsonPath('modules.leave.enabled', true)
            ->assertJsonStructure(['modules' => ['leave' => ['routes', 'menus', 'permissions', 'policies', 'workflow_definitions', 'settings', 'notifications', 'reports', 'audit_events']]]);
    }

    private function manifest(string $code, array $permissions = [], array $dependencies = []): array
    {
        return [
            'code' => $code, 'name_th' => 'โมดูลทดสอบ', 'version' => '1.0.0',
            'enabled' => true, 'dependencies' => $dependencies, 'order' => 1,
            'permissions' => $permissions,
        ];
    }
}
