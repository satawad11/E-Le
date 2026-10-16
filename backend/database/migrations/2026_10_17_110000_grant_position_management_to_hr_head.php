<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * สิทธิ์ "จัดการทะเบียนตำแหน่งและผูกตำแหน่งบุคลากร" ให้งานบุคลากร (ORG-ACCESS-02) — เจ้าของระบบตัดสิน 2026-10-16
 *
 * ไม่ใช้ `system.user.manage` เพราะสิทธิ์นั้นเปิด PUT /api/admin/users ซึ่งรับ `role_id` และ
 * `custom_permissions` โดยไม่ตรวจว่ามอบอะไร — ถ้าให้ HR Head (ซึ่งเจ้าหน้าที่บุคลากรทุกคนใช้ตาม Q4)
 * ทุกคนจะตั้งตัวเองเป็น Super Admin ได้ สิทธิ์นี้จึงแยกมาเฉพาะงานตำแหน่ง
 *
 * ข้อมูลเท่านั้น ไม่เปลี่ยน schema · down() ถอนเฉพาะที่ migration นี้มอบ
 */
return new class extends Migration
{
    private const PERMISSION = 'system.position.manage';

    private const ROLES = ['HR Head', 'Super Admin'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => $guard]);

        Role::whereIn('name', self::ROLES)->where('guard_name', $guard)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::where('name', self::PERMISSION)->where('guard_name', $guard)->first();

        if ($permission) {
            Role::whereIn('name', self::ROLES)->where('guard_name', $guard)->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
