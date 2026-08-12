<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    /**
     * Return all available system modules and their permissions.
     */
    public function modules()
    {
        $modules = config('modules', []);
        $allPermissions = Permission::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'modules' => $modules,
            'permissions' => $allPermissions,
        ]);
    }

    /**
     * Get specific user permissions (Role permissions vs Direct custom permissions).
     */
    public function getUserPermissions(User $user)
    {
        $user->load('roles', 'permissions');

        // Direct permissions assigned directly to this user
        $directPermissions = $user->getDirectPermissions()->pluck('name')->toArray();

        // Permissions inherited via roles
        $rolePermissions = $user->getPermissionsViaRoles()->pluck('name')->toArray();

        // Effective all permissions
        $allPermissions = $user->getAllPermissions()->pluck('name')->toArray();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
            'direct_permissions' => $directPermissions,
            'role_permissions' => $rolePermissions,
            'all_permissions' => array_values(array_unique($allPermissions)),
        ]);
    }

    /**
     * Update direct permissions for a specific user.
     */
    public function updateUserPermissions(Request $request, User $user)
    {
        $request->validate([
            'direct_permissions' => 'present|array',
            'direct_permissions.*' => 'string|exists:permissions,name',
        ]);

        $permissions = $request->input('direct_permissions', []);

        // Spatie syncPermissions บันทึกลง pivot table อัตโนมัติ ไม่ต้องบันทึกใน users table
        $user->syncPermissions($permissions);

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตสิทธิ์การเข้าถึงเฉพาะบุคคลเรียบร้อยแล้ว',
            'direct_permissions' => $user->getDirectPermissions()->pluck('name')->toArray(),
            'all_permissions' => array_values(array_unique($user->getAllPermissions()->pluck('name')->toArray())),
        ]);
    }

    /**
     * Return count of users who have each permission (via role or direct).
     */
    public function userCounts()
    {
        $permissions = Permission::orderBy('name')->get();
        $counts = [];

        foreach ($permissions as $permission) {
            $counts[$permission->name] = $permission->users()->count();
        }

        return response()->json($counts);
    }
}
