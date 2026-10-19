<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * สิทธิ์ "จัดการวันลา (HR)" ให้งานบุคลากร — เจ้าของระบบตัดสิน 2026-10-16 (DELEGATION-01)
 *
 * โค้ดตรวจ `leave.management.manage` อยู่หลายสิบจุด (บันทึกวันลาแทน ปรับยอด ตรวจหลักฐาน
 * ทะเบียนการปฏิบัติหน้าที่แทน การบันทึกการลงนามแทนผู้มีอำนาจ) แต่ไม่เคยมี migration/seeder หลัก
 * สร้าง permission นี้ ฐานข้อมูลที่ติดตั้งจริงจึงไม่มี — ใช้ได้เฉพาะ Super Admin
 *
 * สร้าง permission และมอบให้บทบาท "HR Head" (ชื่อบทบาทจาก RolesAndPermissionsSeeder) ถ้ามีบทบาทนี้
 * ไม่ถอนสิทธิ์ใน down() เพราะผู้ดูแลอาจมอบเพิ่มเองภายหลัง — ถอนเฉพาะที่ migration นี้มอบ
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::firstOrCreate(['name' => 'leave.management.manage', 'guard_name' => $guard]);

        Role::where('name', 'HR Head')->where('guard_name', $guard)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::where('name', 'leave.management.manage')->where('guard_name', $guard)->first();

        if ($permission) {
            Role::where('name', 'HR Head')->where('guard_name', $guard)->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
