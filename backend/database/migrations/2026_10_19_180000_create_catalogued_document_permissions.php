<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * สิทธิ์ของศูนย์จัดการเอกสารที่ route ตรวจอยู่ (routes/api.php) แต่เดิมสร้างโดย seeder เท่านั้น
 *
 * ระบบที่ติดตั้งจาก migration อย่างเดียว (เช่น CI) จึงไม่มีแถวสิทธิ์เหล่านี้ และหน้าจัดการบทบาท
 * มอบให้บทบาทใดไม่ได้ — สร้างแถวไว้ (ไม่มอบให้ใคร) ฐานข้อมูลที่มีอยู่แล้วไม่เปลี่ยน
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'document.template.view', 'document.template.manage',
        'document.report.view', 'document.report.manage',
        'document.final.download',
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach (self::PERMISSIONS as $name) {
            if (! DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** ไม่ลบ — แถวเหล่านี้อาจมีอยู่ก่อน migration นี้ (จาก seeder) และอาจถูกมอบให้บทบาทแล้ว */
    public function down(): void {}
};
