<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * หน้าจัดการบทบาทและสิทธิ์ (ภาษาไทยทั้งหน้า)
 *
 * - หน้าจอแสดง "ชื่อบทบาท" จากคอลัมน์ description และคำอธิบายสิทธิ์จาก PermissionCatalog ไม่แสดงชื่อภายใน
 * - ชื่อภายใน (roles.name) แก้จากหน้าจอไม่ได้ เพราะโค้ดบางส่วนยังอ้างชื่อบทบาทหลัก
 *   บทบาทใหม่ได้ชื่อภายในอัตโนมัติ
 * - บทบาทหลักของระบบลบไม่ได้ บทบาทที่ยังมีผู้ใช้ลบไม่ได้
 */
class RoleController extends Controller
{
    /** บทบาทที่โค้ดอ้างชื่อ หรือเป็นบทบาทพื้นฐานของผู้ใช้ */
    public const CORE_ROLES = ['Super Admin', 'SuperAdmin', 'Director', 'Deputy Director', 'HR Head', 'Employee'];

    public function index(Request $request): JsonResponse
    {
        $labels = PermissionCatalog::labels();
        $roles = Role::with(['permissions'])->orderBy('id')->get()->map(function (Role $role) use ($labels) {
            $users = DB::table('model_has_roles')->where('role_id', $role->id)->count();
            $core = $this->isCore($role);

            return [
                'id' => $role->id,
                // ชื่อภายใน — หน้าจัดการลำดับอนุมัติยังใช้อ้างอิง หน้าจัดการบทบาทไม่แสดง
                'name' => $role->name,
                'display_name' => $role->description ?: $role->name,
                'description' => $role->description,
                'users_count' => $users,
                'is_core' => $core,
                'is_full_access' => in_array($role->name, ['Super Admin', 'SuperAdmin'], true),
                'can_delete' => ! $core && $users === 0,
                'permissions' => $role->permissions->map(fn ($p) => [
                    'id' => $p->id, 'label' => $labels[$p->name]['label'] ?? $p->name, 'module' => $labels[$p->name]['module'] ?? PermissionCatalog::OTHER_GROUP,
                ])->values(),
            ];
        });

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /** สิทธิ์ทั้งหมด จัดกลุ่มตามโมดูลพร้อมคำอธิบายภาษาไทย */
    public function permissions(Request $request): JsonResponse
    {
        $groups = PermissionCatalog::groups();

        // data = รายการแบนเดิม (หน้าจัดการผู้ใช้ยังใช้) · groups = จัดกลุ่มสำหรับหน้าจัดการบทบาท
        return response()->json([
            'success' => true,
            'data' => collect($groups)->flatMap(fn ($g) => collect($g['permissions'])->map(fn ($p) => $p + ['module' => $g['name']]))->values(),
            'groups' => $groups,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $role = Role::create([
            'name' => $this->internalName(),
            'guard_name' => 'web',
            'description' => $data['display_name'],
        ]);
        $role->permissions()->sync($data['permission_ids'] ?? []);
        app(AuditLogger::class)->log('role.created', 'role', $role->id, null, $role->load('permissions')->toArray());
        Cache::flush();

        return response()->json(['success' => true, 'message' => 'สร้างบทบาทใหม่เรียบร้อยแล้ว', 'id' => $role->id], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validated($request, $role);
        $before = $role->load('permissions')->toArray();

        // ชื่อภายในไม่เปลี่ยน — แก้ได้เฉพาะชื่อที่แสดงและชุดสิทธิ์
        $role->description = $data['display_name'];
        $role->save();
        $role->permissions()->sync($data['permission_ids'] ?? []);
        app(AuditLogger::class)->log('role.updated', 'role', $role->id, $before, $role->load('permissions')->toArray());
        Cache::flush();

        return response()->json(['success' => true, 'message' => 'บันทึกบทบาทและสิทธิ์เรียบร้อยแล้ว']);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($this->isCore($role)) {
            return response()->json(['success' => false, 'message' => 'บทบาทหลักของระบบลบไม่ได้'], 422);
        }
        if ($role->users()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'ลบไม่ได้ เพราะยังมีผู้ใช้ที่ได้รับบทบาทนี้อยู่'], 422);
        }

        $before = $role->load('permissions')->toArray();
        $role->delete();
        app(AuditLogger::class)->log('role.deleted', 'role', $role->id, $before);
        Cache::flush();

        return response()->json(['success' => true, 'message' => 'ลบบทบาทเรียบร้อยแล้ว']);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'display_name' => 'required|string|max:255',
            'permission_ids' => 'present|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ], [], [
            'display_name' => 'ชื่อบทบาท',
            'permission_ids' => 'สิทธิ์',
            'permission_ids.*' => 'สิทธิ์',
        ]);
    }

    private function isCore(Role $role): bool
    {
        return in_array($role->name, self::CORE_ROLES, true) || $role->name === config('authorization.base_role', 'Employee');
    }

    private function internalName(): string
    {
        do {
            $name = 'custom-'.Str::lower(Str::random(8));
        } while (Role::where('name', $name)->exists());

        return $name;
    }
}
