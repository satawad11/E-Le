<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * แยกสิทธิ์ "ยกเลิกใบลาของผู้อื่น" ออกจาก system.user.view
 *
 * เดิม LeaveCancellationController ยอมให้ผู้ถือ system.user.view (หรือ view_users ผ่าน alias)
 * ยกเลิกใบลาของใครก็ได้ เพราะใช้แทนการเช็คชื่อ role HR Head — สิทธิ์ดูรายชื่อผู้ใช้
 * จึงพ่วงสิทธิ์ยกเลิกใบลามาด้วยโดยไม่ตั้งใจ
 *
 * มอบสิทธิ์ใหม่ให้ role/ผู้ใช้ที่ "ยกเลิกได้อยู่แล้ว" เท่านั้น ไม่มีใครเสียหรือได้สิทธิ์เพิ่ม
 * จากนั้นผู้ดูแลระบบถอนออกจาก role ที่ไม่ควรมีได้ที่หน้าจัดการบทบาท
 */
return new class extends Migration
{
    private const PERMISSION = 'leave.request.cancel_others';

    private const PREVIOUSLY_ALLOWED = ['system.user.view', 'view_users'];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => $guard]);
        $holders = fn ($query) => $query->whereIn('name', self::PREVIOUSLY_ALLOWED);

        Role::whereHas('permissions', $holders)->each(fn ($role) => $role->givePermissionTo($permission));

        $userModel = config('auth.providers.users.model');
        $userModel::whereHas('permissions', $holders)->each(fn ($user) => $user->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
