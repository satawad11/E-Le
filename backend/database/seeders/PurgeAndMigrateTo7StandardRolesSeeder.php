<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PurgeAndMigrateTo7StandardRolesSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Exact 7 Thai Standard Roles
        $allowed7Roles = [
            'ผู้ดูแลระบบสูงสุด',
            'ผู้อำนวยการ',
            'รองผู้อำนวยการ',
            'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
            'เจ้าหน้าที่บุคลากร (HR)',
            'เจ้าหน้าที่สารบรรณกลาง',
            'บุคลากร / ครู / เจ้าหน้าที่',
        ];

        // Ensure the 7 standard roles exist
        $this->call(StandardRolesAndPermissionsSeeder::class);

        // 2. Re-assign all users to the exact 7 Thai Standard Roles
        $users = User::all();
        foreach ($users as $user) {
            $currentRoleNames = $user->getRoleNames()->toArray();
            $targetRole = 'บุคลากร / ครู / เจ้าหน้าที่'; // Default fallback

            if (str_contains($user->email, 'superadmin') || in_array('Super Admin', $currentRoleNames) || in_array('SuperAdmin', $currentRoleNames) || in_array('ผู้ดูแลระบบสูงสุด', $currentRoleNames)) {
                $targetRole = 'ผู้ดูแลระบบสูงสุด';
            } elseif ((str_contains($user->email, 'director') && ! str_contains($user->email, 'deputy')) || in_array('Director', $currentRoleNames) || in_array('ผู้อำนวยการ', $currentRoleNames)) {
                $targetRole = 'ผู้อำนวยการ';
            } elseif (str_contains($user->email, 'deputy') || in_array('Deputy Director', $currentRoleNames) || in_array('DeputyDirector', $currentRoleNames) || in_array('รองผู้อำนวยการ', $currentRoleNames)) {
                $targetRole = 'รองผู้อำนวยการ';
            } elseif (str_contains($user->email, 'hr') || in_array('HR Head', $currentRoleNames) || in_array('HROfficer', $currentRoleNames) || in_array('เจ้าหน้าที่บุคลากร (HR)', $currentRoleNames)) {
                $targetRole = 'เจ้าหน้าที่บุคลากร (HR)';
            } elseif (str_contains($user->email, 'doc') || str_contains($user->email, 'saraban') || in_array('Document Officer', $currentRoleNames) || in_array('SarabanOfficer', $currentRoleNames) || in_array('admin_saraban', $currentRoleNames) || in_array('เจ้าหน้าที่สารบรรณกลาง', $currentRoleNames)) {
                $targetRole = 'เจ้าหน้าที่สารบรรณกลาง';
            } elseif (in_array('HeadOfDepartment', $currentRoleNames) || in_array('หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', $currentRoleNames)) {
                $targetRole = 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย';
            }

            $user->syncRoles([$targetRole]);
        }

        // 3. Delete all roles that are NOT in the exact 7 Thai Standard Roles
        $obsoleteRoles = Role::whereNotIn('name', $allowed7Roles)->get();
        foreach ($obsoleteRoles as $role) {
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            $role->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
