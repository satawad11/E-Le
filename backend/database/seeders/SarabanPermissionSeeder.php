<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SarabanPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'saraban.document.view',
            'saraban.document.create',
            'saraban.document.edit',
            'saraban.document.delete',
            'saraban.document.approve',
            'saraban.document.sign',
            'saraban.document.circulate',
            'saraban.document.download',
            'saraban.document.view_classified',
            'saraban.document.manage_types',
            'saraban.document.report',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'admin_saraban', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($permissions);

        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $managerRole->givePermissionTo([
            'saraban.document.view',
            'saraban.document.approve',
            'saraban.document.sign',
            'saraban.document.download',
            'saraban.document.view_classified',
            'saraban.document.report',
        ]);

        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staffRole->givePermissionTo([
            'saraban.document.view',
            'saraban.document.create',
            'saraban.document.edit',
            'saraban.document.download',
        ]);
    }
}
