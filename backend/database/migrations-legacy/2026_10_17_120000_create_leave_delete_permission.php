<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * สิทธิ์ "ลบใบลา" แยกจากงานจัดการวันลา — มอบให้ Super Admin เท่านั้น (เจ้าของระบบตัดสิน 2026-10-16)
 *
 * เดิมการลบใบลาผูกกับ `leave.management.manage` เมื่อมอบสิทธิ์นั้นให้ HR Head (DELEGATION-01)
 * งานบุคลากรจึงลบใบลาที่อนุมัติแล้วของใครก็ได้ — ขัดกฎ "ไม่ลบประวัติสำคัญ"
 * การแก้ข้อมูลการลาตามปกติให้ใช้การยกเลิกวันลาซึ่งผ่านสายอนุมัติ
 *
 * ข้อมูลเท่านั้น ไม่เปลี่ยน schema · down() ถอนเฉพาะที่ migration นี้มอบ
 */
return new class extends Migration
{
    private const PERMISSION = 'leave.delete';

    private const ROLES = ['Super Admin', 'SuperAdmin'];

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
