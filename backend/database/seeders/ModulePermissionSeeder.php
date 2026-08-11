<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ModulePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = config('modules', []);

        foreach ($modules as $moduleId => $module) {
            if (isset($module['permissions']) && is_array($module['permissions'])) {
                foreach ($module['permissions'] as $name => $description) {
                    Permission::firstOrCreate(
                        ['name' => $name, 'guard_name' => 'web']
                    );
                }
            }
        }
    }
}
