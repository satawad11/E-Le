<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SarabanPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'saraban.document.view',
            'saraban.document.view_classified',
            'saraban.document.create',
            'saraban.document.edit',
            'saraban.document.delete',
            'saraban.document.approve',
            'saraban.document.sign',
            'saraban.document.manage_types',
            'saraban.document.report',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Registrars (เจ้าหน้าที่สารบัญ) — full control over the register.
        $registrarPermissions = $permissions;

        // Approvers (ผู้บริหาร) — read, approve and sign, but do not maintain the register.
        $approverPermissions = [
            'saraban.document.view',
            'saraban.document.view_classified',
            'saraban.document.approve',
            'saraban.document.sign',
            'saraban.document.report',
        ];

        // Staff — read and author their own documents.
        $staffPermissions = [
            'saraban.document.view',
            'saraban.document.create',
            'saraban.document.edit',
        ];

        // Role names differ between the Leave system's seeder and this module's own
        // seeder; grant both sets so authorization does not depend on which one ran.
        $rolesByTier = [
            'registrar' => ['admin_saraban', 'Document Officer', 'Saraban Officer', 'เจ้าหน้าที่สารบัญ'],
            'approver' => ['manager', 'Director', 'Deputy Director', 'HR Head'],
            'staff' => ['staff', 'Employee'],
        ];

        $permissionsByTier = [
            'registrar' => $registrarPermissions,
            'approver' => $approverPermissions,
            'staff' => $staffPermissions,
        ];

        foreach ($rolesByTier as $tier => $roleNames) {
            foreach ($roleNames as $roleName) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                $role->givePermissionTo($permissionsByTier[$tier]);
            }
        }

        // Super Admin holds every permission in the system, including the ones
        // created above (RolesAndPermissionsSeeder runs before this one).
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());
    }
}
