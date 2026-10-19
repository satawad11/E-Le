<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * มอบ "จัดการวันลา (HR)" ให้บทบาท Super Admin โดยตรง — เจ้าของระบบตัดสิน 2026-10-16 (DELEGATION-01)
 *
 * Super Admin ผ่านสิทธิ์นี้อยู่แล้วโดยปริยาย แต่ไม่เห็นในหน้าจัดการสิทธิ์ เพราะ
 * RolesAndPermissionsSeeder มอบ "ทุกสิทธิ์ที่มีตอน seed" ซึ่งตอนนั้นยังไม่มีสิทธิ์นี้
 * migration นี้ทำให้ฐานข้อมูลที่ติดตั้งแล้วตรงกับการติดตั้งใหม่
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::firstOrCreate(['name' => 'leave.management.manage', 'guard_name' => $guard]);

        Role::whereIn('name', ['Super Admin', 'SuperAdmin'])->where('guard_name', $guard)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::where('name', 'leave.management.manage')->where('guard_name', $guard)->first();

        if ($permission) {
            Role::whereIn('name', ['Super Admin', 'SuperAdmin'])->where('guard_name', $guard)->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
