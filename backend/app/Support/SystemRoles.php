<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-026 — ตัวระบุบทบาทระบบที่เปลี่ยนไม่ได้
 *
 * ปัญหาเดิม: `RoleController` ป้องกันบทบาทหลักด้วยการเทียบ "ชื่อที่แสดง"
 * (`SuperAdmin`, `Director`, `Employee`) แต่บทบาทจริงในฐานข้อมูลชื่อ
 * `Super Admin` (มีเว้นวรรค) ด่านนั้นจึงไม่เคยทำงานเลยแม้แต่ครั้งเดียว —
 * ผู้ดูแลเปลี่ยนชื่อหรือลบบทบาทผู้ดูแลระบบสูงสุดทิ้งได้ และระบบจะไม่เหลือใคร
 * ที่แก้กลับได้อีก
 *
 * นอกจากนั้นชื่อที่แสดงยังเป็น "ข้อมูล" ที่ผู้ดูแลแก้ได้จากหน้าจอ ตาม
 * `PROJECT_RULES.md` จึงห้ามใช้เป็น identity
 *
 * ไฟล์นี้จึงนิยาม **คีย์ระบบ** ที่แก้ไม่ได้ (`roles.system_key`) แยกจากชื่อที่แสดง
 * ส่วน `aliases` ใช้เพียงครั้งเดียวตอน migration เพื่อจับคู่แถวเดิมที่มีอยู่แล้ว
 * เข้ากับคีย์ ไม่ได้ถูกใช้ตัดสินสิทธิ์ใด ๆ ขณะทำงาน
 */
final class SystemRoles
{
    public const SUPER_ADMIN = 'super_admin';

    public const DIRECTOR = 'director';

    public const DEPUTY_DIRECTOR = 'deputy_director';

    public const HR_HEAD = 'hr_head';

    public const EMPLOYEE = 'employee';

    /**
     * คีย์ระบบ -> ชื่อที่แสดงซึ่งเคยใช้อ้างถึงบทบาทนั้น
     *
     * ใช้เพื่อ backfill ข้อมูลเดิมเท่านั้น ห้ามนำไปใช้ตัดสินสิทธิ์
     *
     * @return array<string, array<int, string>>
     */
    public static function legacyNameAliases(): array
    {
        return [
            self::SUPER_ADMIN => ['Super Admin', 'SuperAdmin'],
            self::DIRECTOR => ['Director'],
            self::DEPUTY_DIRECTOR => ['Deputy Director'],
            self::HR_HEAD => ['HR Head'],
            self::EMPLOYEE => ['Employee'],
        ];
    }

    /**
     * คีย์ระบบทั้งหมด
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::legacyNameAliases());
    }

    /**
     * ติดคีย์ระบบให้บทบาทที่มีอยู่แล้วแต่ยังไม่มีคีย์ (idempotent)
     *
     * ใช้ทั้งใน migration ของระบบที่ติดตั้งไปแล้ว และท้าย seeder ของการติดตั้งใหม่
     * เพราะ seeder สร้างบทบาท "หลัง" migration รันไปแล้ว
     *
     * @return int จำนวนแถวที่ถูกติดคีย์ในรอบนี้
     */
    public static function backfillExistingRoles(): int
    {
        if (! Schema::hasColumn('roles', 'system_key')) {
            return 0;
        }

        $stamped = 0;

        foreach (self::legacyNameAliases() as $key => $names) {
            if (DB::table('roles')->where('system_key', $key)->exists()) {
                continue;
            }

            $id = DB::table('roles')
                ->whereIn('name', $names)
                ->whereNull('system_key')
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                DB::table('roles')->where('id', $id)->update(['system_key' => $key, 'is_system' => true]);
                $stamped++;
            }
        }

        return $stamped;
    }
}
