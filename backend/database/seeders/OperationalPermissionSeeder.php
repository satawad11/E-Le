<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * LEGACY-04 (ปิดงาน) — ชุดสิทธิ์ที่ระบบบังคับใช้จริง พร้อมการแจกตามหน้าที่
 *
 * `LEGACY-04-REPORT.md` พบว่า route บังคับ permission 7 ตัวที่ **ไม่มีอยู่ใน
 * ฐานข้อมูลเลย** ผลคือ endpoint เหล่านั้นใช้ได้เฉพาะ Super Admin
 * และที่ร้ายที่สุดคือบุคลากรทั่วไปลงเวลาเข้า-ออกไม่ได้
 *
 * เจ้าของระบบอนุมัติให้เติมเมื่อ 2026-08-14 (Decision 1 / Decision 2)
 *
 * ไฟล์นี้เป็น **แหล่งความจริงเดียว** ของการแจกสิทธิ์ชุดนี้
 * ถูกเรียกจากสองทาง:
 *   1. migration `2026_08_14_100000_seed_operational_permissions` (ระบบที่ติดตั้งแล้ว)
 *   2. `RolesAndPermissionsSeeder` (ติดตั้งใหม่ และชุดทดสอบ)
 *
 * idempotent — รันซ้ำได้ และ **ไม่ถอนสิทธิ์ของใคร** (ใช้ givePermissionTo ไม่ใช่ sync)
 */
class OperationalPermissionSeeder extends Seeder
{
    /**
     * ชื่อ permission => คำอธิบายภาษาไทย
     *
     * ทุกชื่อเป็น canonical ที่โค้ดบังคับใช้อยู่แล้ว ยกเว้น
     * `saraban.document.register` ที่ต้องสร้างใหม่ เพราะ Decision 2 กำหนดให้
     * "รับ/ส่ง" แยกจากสิทธิ์อื่น ขณะที่ของเดิมฝังรวมอยู่กับ
     * `saraban.document.manage_types` (ตั้งค่าประเภทหนังสือ) ซึ่งเป็นคนละหน้าที่
     *
     * @var array<string,string>
     */
    public const PERMISSIONS = [
        // Decision 1 — 7 ตัวที่ route บังคับใช้แต่ไม่เคยถูกสร้าง
        'attendance.checkin' => 'บันทึกเวลาเข้า-ออกงานของตนเอง',
        'attendance.view_self' => 'ดูประวัติการลงเวลาของตนเอง',
        'attendance.ot_summary' => 'ดูสรุปการทำงานล่วงเวลาของทั้งองค์กร',
        'system.menu.manage' => 'จัดการเมนูและบทบาทของระบบ',
        'admin.users.manage_permissions' => 'กำหนดสิทธิ์รายบุคคล',
        'saraban.setting.manage' => 'ตั้งค่าระบบสารบรรณ',

        // Decision 2 — แยกสิทธิ์งานสารบรรณตามหน้าที่
        'saraban.document.view' => 'ดูทะเบียนหนังสือราชการ',
        'saraban.document.create' => 'สร้างหนังสือราชการ',
        'saraban.document.edit' => 'แก้ไขหนังสือราชการ',
        'saraban.document.register' => 'รับ/ส่งหนังสือราชการ (เจ้าหน้าที่ทะเบียน)',
        'saraban.circular.broadcast' => 'ออกหนังสือเวียน',
        'saraban.document.report' => 'ดูรายงานทะเบียนหนังสือ',
        'saraban.document.delete' => 'ลบหนังสือราชการ',
        'saraban.document.view_classified' => 'เข้าถึงหนังสือชั้นความลับ',
        'saraban.document.manage_types' => 'จัดการประเภทหนังสือและสายงานอนุมัติ',
        'saraban.document.approve' => 'กลั่นกรอง/อนุมัติหนังสือราชการ',
        'saraban.document.sign' => 'ลงนามหนังสือราชการ',

        // สายคำสั่ง OT (เจ้าของระบบกำหนด 2026-08-19)
        'overtime.operation.execute' => 'ออกคำสั่งปฏิบัติงานล่วงเวลา',
        'overtime.management.review' => 'อนุญาต/อนุมัติคำสั่ง OT มอบหมายผู้ปฏิบัติงาน และรับรองเวลา',
    ];

    /**
     * role => permission ที่ได้รับเพิ่ม (Principle of Least Privilege)
     *
     * role ที่ไม่ปรากฏในตารางนี้ไม่ได้อะไรเพิ่มเลย
     * สิทธิ์ที่ไม่ปรากฏกับ role ใดเลย = Super Admin เท่านั้น และผู้ดูแลมอบหมาย
     * รายบุคคลเพิ่มได้ผ่านหน้าจัดการสิทธิ์ (เช่น `saraban.setting.manage`
     * ที่ Decision 1 ระบุว่า "เฉพาะผู้ได้รับมอบหมาย")
     *
     * @var array<string,array<int,string>>
     */
    public const GRANTS = [
        /*
         * บุคลากรทั่วไป — ได้เฉพาะสิทธิ์ลงเวลาของ "ตนเอง"
         * ไม่มีสิทธิ์ใดที่ทำให้เห็นการลงเวลาของคนอื่น
         */
        'Employee' => [
            'attendance.checkin',
            'attendance.view_self',
        ],

        /*
         * ผู้บริหารและ HR ก็ต้องลงเวลาเช่นกัน
         * `attendance.ot_summary` ให้เพราะทั้งสามคนถือ `attendance.view_all`
         * อยู่แล้ว จึงเห็นข้อมูลการลงเวลาทั้งองค์กรได้ตั้งแต่ต้น — สรุป OT
         * ไม่ใช่ข้อมูลชั้นใหม่สำหรับกลุ่มนี้
         */
        /*
         * ผู้บริหารเป็นผู้กลั่นกรองและลงนามหนังสือราชการ
         *
         * CORE CHECKPOINT (2026-08-14): LEGACY-04 ปิดงานโดยยังไม่ให้สิทธิ์ชุดนี้
         * เพราะการตัดสินใจตอนนั้นครอบคลุมเฉพาะ Document Officer ผลคือหนังสือ
         * ราชการอนุมัติได้เฉพาะ Super Admin และโมดูลสารบรรณใช้งานจริงไม่ได้
         * เจ้าของระบบอนุมัติให้เติมเมื่อ 2026-08-14
         *
         * `view` จำเป็นเพราะ DocumentPolicy::approve() เรียก view() ก่อนเสมอ
         * และ view() ยังจำกัดให้เห็นเฉพาะเอกสารที่ตนเกี่ยวข้องอยู่ดี
         * ส่วนเอกสารชั้นความลับยังต้องมี `view_classified` แยกต่างหากเหมือนเดิม
         */
        /*
         * สายคำสั่ง OT ที่เจ้าของระบบกำหนดเมื่อ 2026-08-19:
         *   หัวหน้าบุคลากร ตั้งคำสั่ง → รอง ผอ. ฝ่ายทรัพยากร อนุญาต → ผอ. อนุมัติ
         *
         * ทั้งสองตำแหน่งเป็นผู้พิจารณาในสายเดียวกันจึงได้
         * `overtime.management.review` เท่ากัน ส่วนการแยกว่าใครทำขั้นไหน
         * บังคับด้วย workflow step ที่ผูกกับ role ไม่ใช่ด้วยสิทธิ์
         * (ดู OvertimeOrderWorkflowProvisioner::CHAIN)
         */
        'Director' => [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot_summary',
            'overtime.management.review',
            'saraban.document.view',
            'saraban.document.approve',
            'saraban.document.sign',
            'saraban.document.report',
        ],

        'Deputy Director' => [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot_summary',
            'overtime.management.review',
            'saraban.document.view',
            'saraban.document.approve',
            'saraban.document.sign',
            'saraban.document.report',
        ],

        /*
         * HR เป็นผู้ดูแล OT ตามหน้าที่จริง จึงได้ทั้งบันทึกและดูสรุป
         *
         * `overtime.operation.execute` = ผู้ตั้งคำสั่ง OT ตามที่เจ้าของระบบ
         * กำหนดเมื่อ 2026-08-19 — ตั้งได้อย่างเดียว ไม่ได้สิทธิ์อนุมัติของตนเอง
         */
        'HR Head' => [
            'attendance.checkin',
            'attendance.view_self',
            'attendance.ot_summary',
            'overtime.operation.execute',
        ],

        /*
         * Document Officer = เจ้าหน้าที่สารบรรณ (Decision 2)
         *
         * ได้สิทธิ์พอที่จะทำงานสารบรรณได้จริง แต่ **ไม่ได้** สี่ตัวนี้:
         *   saraban.document.delete           ลบหนังสือ
         *   saraban.document.view_classified  หนังสือชั้นความลับ
         *   saraban.document.manage_types     ตั้งค่าประเภทหนังสือ/สายงาน
         *   saraban.setting.manage            ตั้งค่าระบบสารบรรณ
         *
         * ตามที่ Decision 2 สั่งว่าห้ามให้โดยอัตโนมัติ — มอบหมายรายบุคคลได้
         */
        'Document Officer' => [
            'attendance.checkin',
            'attendance.view_self',
            'saraban.document.view',
            'saraban.document.create',
            'saraban.document.edit',
            'saraban.document.register',
            'saraban.circular.broadcast',
            'saraban.document.report',
        ],
    ];

    /**
     * role ที่ถือทุก permission ในระบบตามการออกแบบเดิม
     *
     * @var array<int,string>
     */
    public const SUPER_ADMIN_ROLES = ['Super Admin', 'SuperAdmin'];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $now = now();
        $guard = 'web';
        $permissionIds = [];

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

            $permissionIds[$name] = $id ?? DB::table('permissions')->insertGetId([
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::GRANTS as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

            // ไม่สร้าง role ใหม่จากที่นี่ — ติดตั้งที่ไม่มี role นี้ให้ข้ามไป
            if ($roleId === null) {
                continue;
            }

            foreach ($names as $name) {
                $this->attach($permissionIds[$name], $roleId);
            }
        }

        foreach (self::SUPER_ADMIN_ROLES as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                $this->attach($permissionId, $roleId);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function attach(int $permissionId, int $roleId): void
    {
        $exists = DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->exists();

        if (! $exists) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }
}
