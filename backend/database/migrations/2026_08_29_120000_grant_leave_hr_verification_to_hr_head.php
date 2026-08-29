<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * หัวหน้าบุคลากรต้องตรวจสอบสถิติการลาได้
 *
 * ปุ่ม "ตรวจสอบสถิติการลา" บังคับสิทธิ์ `leave.approval.hr` แต่ไม่มีบทบาทใดในระบบ
 * ได้รับสิทธิ์นี้เลยนอกจาก Super Admin — รวมถึง `HR Head` ซึ่งเป็นผู้ทำหน้าที่นี้จริง
 * และเป็น role ที่ผูกกับขั้นตอน "ตรวจสอบโดยหัวหน้าบุคลากร" ในสายอนุมัติมาตรฐาน
 *
 * บทบาททั้งสาม (หัวหน้าบุคลากร รองผู้อำนวยการ ผู้อำนวยการ) ได้เพียงชื่อชุดเดิม
 * `approve_leave` ซึ่งเทียบเท่ากับ `leave.approval.dept` เท่านั้น
 *
 * ที่ผ่านมาไม่มีใครสังเกตเพราะบัญชีที่ใช้ทดสอบถูกมอบบทบาท Super Admin ไว้
 * ซึ่งผ่าน `Gate::before` ทุกกรณีอยู่แล้ว จึงกดปุ่มได้โดยไม่เกี่ยวกับสิทธิ์ที่ถืออยู่จริง
 *
 * migration นี้แจกสิทธิ์ให้ระบบที่ติดตั้งไปแล้ว ส่วนการติดตั้งใหม่ได้จาก
 * `OperationalPermissionSeeder` ซึ่งเป็นสารบัญสิทธิ์ตามหน้าที่
 */
return new class extends Migration
{
    private const ROLE = 'HR Head';

    private const PERMISSION = 'leave.approval.hr';

    public function up(): void
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();

        if ($role === null) {
            return;
        }

        Permission::findOrCreate(self::PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->givePermissionTo(self::PERMISSION);
    }

    public function down(): void
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();

        if ($role !== null && Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $role->revokePermissionTo(self::PERMISSION);
        }
    }
};
