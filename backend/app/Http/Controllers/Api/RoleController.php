<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * List all roles with permissions and user count.
     */
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with(['permissions'])
            ->orderBy('id', 'asc')
            ->get();

        $roles->each(function ($role) {
            $role->users_count = DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->count();
        });

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Get all available permissions.
     */
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::orderBy('name', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
            'description' => 'nullable|string|max:255',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $role = Role::create([
            'name' => trim($request->input('name')),
            'guard_name' => 'web',
            'description' => $request->input('description'),
        ]);

        if ($request->has('permission_ids')) {
            $role->permissions()->sync($request->input('permission_ids', []));
        }
        app(AuditLogger::class)->log('role.created', 'role', $role->id, null, $role->load('permissions')->toArray());

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'สร้างบทบาทใหม่สำเร็จแล้ว',
            'data' => $role->load('permissions'),
        ], 201);
    }

    /**
     * Update an existing role.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $before = $role->load('permissions')->toArray();
        $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,'.$role->id,
            'description' => 'nullable|string|max:255',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        /*
         * SEC-026 — บทบาทหลักของระบบเปลี่ยนชื่อไม่ได้
         *
         * เดิมด่านนี้เทียบ "ชื่อที่แสดง" กับรายการ `['SuperAdmin', 'Director', 'Employee']`
         * แต่บทบาทจริงในฐานข้อมูลชื่อ `Super Admin` (มีเว้นวรรค) ด่านจึงไม่เคยทำงาน
         * และชื่อที่แสดงยังเป็นข้อมูลที่ผู้ดูแลแก้ได้ จึงใช้เป็นตัวระบุไม่ได้ตั้งแต่ต้น
         *
         * ตัวระบุที่ใช้จริงคือ `roles.system_key` ซึ่งไม่มี API ใดเปิดให้แก้
         */
        if ($role->isSystemRole() && trim((string) $request->input('name')) !== $role->name) {
            return response()->json([
                'success' => false,
                'message' => "ไม่สามารถเปลี่ยนชื่อบทบาทหลักของระบบ ({$role->name}) ได้",
            ], 422);
        }

        $role->name = trim($request->input('name'));
        $role->description = $request->input('description');
        $role->save();

        if ($request->has('permission_ids')) {
            $role->permissions()->sync($request->input('permission_ids', []));
        }
        app(AuditLogger::class)->log('role.updated', 'role', $role->id, $before, $role->load('permissions')->toArray());

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'อัปเดตบทบาทและสิทธิ์เรียบร้อยแล้ว',
            'data' => $role->load('permissions'),
        ]);
    }

    /**
     * Delete a role.
     */
    public function destroy(Role $role): JsonResponse
    {
        // SEC-026 — ตัดสินจากคีย์ระบบ ไม่ใช่ชื่อที่แสดง (ดูเหตุผลใน update())
        if ($role->isSystemRole()) {
            return response()->json([
                'success' => false,
                'message' => "ไม่สามารถลบบทบาทหลักของระบบ ({$role->name}) ได้",
            ], 422);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่สามารถลบบทบาทนี้ได้ เนื่องจากมีผู้ใช้งานผูกอยู่กับบทบาทนี้',
            ], 422);
        }

        $before = $role->load('permissions')->toArray();
        $role->delete();
        app(AuditLogger::class)->log('role.deleted', 'role', $role->id, $before);
        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'ลบบทบาทเรียบร้อยแล้ว',
        ]);
    }
}
