<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'view_users',
            'edit_users',
            'delete_users',
            'manage_settings',
            'manage_departments',
            'manage_leave_types',
            'manage_workflow_steps',
            'approve_leave',
            'view_all_attendances',
            'export_reports'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign created permissions

        // 1. Employee
        $employeeRole = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        // employees usually have basic permissions natively without explicit assignments

        // 2. HR Head
        $hrHeadRole = Role::firstOrCreate(['name' => 'HR Head', 'guard_name' => 'web']);
        $hrHeadRole->syncPermissions([
            'view_users',
            'approve_leave',
            'view_all_attendances',
            'export_reports'
        ]);

        // 3. Deputy Director
        $deputyRole = Role::firstOrCreate(['name' => 'Deputy Director', 'guard_name' => 'web']);
        $deputyRole->syncPermissions([
            'approve_leave',
            'view_all_attendances',
            'export_reports'
        ]);

        // 4. Director
        $directorRole = Role::firstOrCreate(['name' => 'Director', 'guard_name' => 'web']);
        $directorRole->syncPermissions([
            'approve_leave',
            'view_all_attendances',
            'export_reports'
        ]);

        // 5. Document Officer
        $docOfficerRole = Role::firstOrCreate(['name' => 'Document Officer', 'guard_name' => 'web']);
        $docOfficerRole->syncPermissions([
            'view_all_attendances',
            'export_reports'
        ]);

        // 6. Super Admin
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        // Super Admin has all permissions (handled via Gate in AuthServiceProvider typically, or just assign all)
        $superAdminRole->syncPermissions(Permission::all());
    }
}
