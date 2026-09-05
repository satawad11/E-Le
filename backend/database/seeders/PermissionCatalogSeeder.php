<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * แหล่งความจริงเดียวของ "ชื่อสิทธิ์ที่ระบบอ้างถึง"
 *
 * CLEANUP-01 (D5) — ก่อนหน้านี้ชื่อสิทธิ์ถูกประกาศกระจายอยู่ 5 ที่ที่ไม่ตรงกัน:
 * `RolesAndPermissionsSeeder` (ชุดเดิม), `OperationalPermissionSeeder` (ชุดที่ route
 * บังคับใช้), `ModulePermissionSeeder` + `config/modules.php`, migration
 * `seed_leave_governance_access` และ `StandardRolesAndPermissionsSeeder` ที่ไม่เคยถูกเรียก
 *
 * ผลคือมีชื่อที่ **โค้ดบังคับใช้แต่ไม่เคยถูกสร้าง** เช่น `leave.management.manage`
 * และ `system.role.manage` — endpoint และเมนูที่อ้างชื่อเหล่านั้นจึงใช้ได้เฉพาะ
 * Super Admin (ผ่าน `Gate::before`) ส่วนคนที่ควรมีสิทธิ์กลับถูกปฏิเสธเงียบ ๆ
 *
 * ไฟล์นี้ **สร้างเฉพาะชื่อสิทธิ์ ไม่แจกให้ใคร** การแจกสิทธิ์ยังอยู่ที่
 * `RolesAndPermissionsSeeder` และ `OperationalPermissionSeeder` ตามเดิม
 * การเพิ่มชื่อจึงไม่เปลี่ยนขอบเขตของผู้ใช้คนใดเลย
 *
 * `PermissionCatalogTest` บังคับว่าทุกชื่อที่ route, policy, โค้ด และเมนูอ้างถึง
 * ต้องอยู่ในรายการนี้
 */
class PermissionCatalogSeeder extends Seeder
{
    /**
     * ชุดชื่อมาตรฐานตามโมดูล — ชื่อที่โค้ดใหม่ควรใช้
     *
     * @var array<string,string>
     */
    public const MODULE_PERMISSIONS = [
        // ระบบการลา
        'leave.view' => 'ดูข้อมูลการลา',
        'leave.create' => 'ยื่นใบลา',
        'leave.cancel' => 'ขอยกเลิกใบลา',
        'leave.approve' => 'อนุมัติใบลา',
        'leave.export' => 'ส่งออกข้อมูลการลา',
        'leave.hr_verify' => 'ตรวจสอบใบลาโดยงานบุคลากร',
        'leave.approval.dept' => 'อนุมัติการลาระดับแผนกวิชา/ฝ่าย',
        'leave.approval.final' => 'อนุมัติการลาขั้นสุดท้าย',
        'leave.approval.hr' => 'ตรวจและกำกับการลาในฐานะงานบุคลากร',
        'leave.management.manage' => 'จัดการสิทธิ์วันลาและซิงค์ข้อมูลวันลา',
        'leave.report.view' => 'ดูรายงานและสถิติการลา',
        'leave.policy.manage' => 'จัดการนโยบายสิทธิการลา',
        'leave.calendar.manage' => 'จัดการปฏิทินวันทำการ',
        'leave.evidence.verify' => 'ตรวจสอบหลักฐานประกอบการลา',
        'leave.audit.view' => 'ดูประวัติการดำเนินการของใบลา',

        // ระบบลงเวลา
        'attendance.checkin' => 'บันทึกเวลาเข้า-ออกงานของตนเอง',
        'attendance.view_self' => 'ดูประวัติการลงเวลาของตนเอง',
        'attendance.view_all' => 'ดูรายงานการลงเวลาของบุคลากรทั้งหมด',
        'attendance.export' => 'ส่งออกรายงานเวลาเข้า-ออกงาน',
        'attendance.ot' => 'บันทึกเวลาทำงานล่วงเวลา',
        'attendance.ot_summary' => 'ดูสรุปการทำงานล่วงเวลาของทั้งองค์กร',

        // งานล่วงเวลา
        'overtime.operation.execute' => 'ออกคำสั่งปฏิบัติงานล่วงเวลาและลงเวลา OT',
        'overtime.management.review' => 'อนุญาต/อนุมัติคำสั่ง OT มอบหมายผู้ปฏิบัติงาน และรับรองเวลา',

        // เอกสารและรายงาน
        'document.template.view' => 'ดูแม่แบบเอกสาร',
        'document.template.manage' => 'จัดการแม่แบบเอกสาร',
        'document.report.view' => 'ดูรายงานของศูนย์จัดการเอกสาร',
        'document.report.manage' => 'จัดการนิยามรายงาน',
        'document.generate' => 'ออกเอกสารจากระบบ',
        'document.final.view' => 'ดูเอกสารฉบับสมบูรณ์',
        'document.final.download' => 'ดาวน์โหลดเอกสารฉบับสมบูรณ์',
        'document.audit.view' => 'ดูประวัติการออกเอกสาร',

        /*
         * งานสารบรรณ (INT-02)
         *
         * ต้องตรงกับ `permissions` ของโมดูล `saraban` ใน `config/module_registry.php`
         * ทุกชื่อ — `PermissionCatalogTest` บังคับความตรงกันนั้นไว้แล้ว
         * การเพิ่มชื่อที่นี่ "สร้างชื่อเท่านั้น ไม่แจกให้ใคร" จึงไม่เปลี่ยนสิทธิ์ของผู้ใช้คนใด
         */
        'saraban.document.view' => 'ดูหนังสือและทะเบียนเอกสาร',
        'saraban.document.create' => 'สร้างหนังสือ',
        'saraban.document.update' => 'แก้ไขหนังสือที่ยังไม่ออกเลข',
        'saraban.document.submit' => 'เสนอหนังสือเข้าสู่กระบวนการ',
        'saraban.incoming.register' => 'ลงทะเบียนรับหนังสือ',
        'saraban.incoming.stamp' => 'ประทับตรารับหนังสือ',
        'saraban.number.reserve' => 'จองเลขหนังสือ',
        'saraban.number.allocate' => 'ออกเลขหนังสือ',
        'saraban.number.release' => 'คืนเลขหนังสือที่จองไว้',
        'saraban.number.cancel' => 'ยกเลิกเลขหนังสือ',
        'saraban.workflow.review' => 'ตรวจและดำเนินการหนังสือตามสายงาน',
        'saraban.workflow.forward' => 'ส่งหนังสือต่อไปยังขั้นถัดไป',
        'saraban.workflow.return' => 'ส่งหนังสือกลับไปแก้ไข',
        'saraban.workflow.simulate' => 'จำลองเส้นทางเดินหนังสือ',
        'saraban.workflow.manage' => 'จัดการและเผยแพร่เวอร์ชันสายงานเดินหนังสือ',
        'saraban.endorse.create' => 'เกษียนหนังสือ',
        'saraban.signature.sign' => 'ลงนามในหนังสือ',
        // การเพิกถอน — ผู้ขอกับผู้ตัดสินต้องเป็นคนละคน (SoT §26)
        'saraban.signature.revoke' => 'ยื่นคำขอเพิกถอนลายเซ็นหรือหนังสือ',
        'saraban.signature.revoke_approve' => 'อนุมัติหรือปฏิเสธคำขอเพิกถอน',
        'saraban.distribution.create' => 'จัดทำรายการกระจายหนังสือ',
        'saraban.distribution.send' => 'ส่งหนังสือถึงผู้รับ',
        'saraban.distribution.track' => 'ติดตามการรับทราบและการดำเนินการ',
        'saraban.inbox.view' => 'เปิดกล่องหนังสือเวียนถึงตนเอง',
        /*
         * ชั้นความลับสามชั้นตามระเบียบว่าด้วยการรักษาความลับของทางราชการ พ.ศ. 2544
         *
         * เป็นด่านเพิ่มจากขอบเขตหน่วยงาน ไม่ใช่ด่านแทน · และผู้ได้รับอนุญาต
         * ชั้นสูงกว่าเข้าถึงชั้นต่ำกว่าได้ จึงไม่ต้องแจกทั้งสามข้อให้คนเดียวกัน
         */
        'saraban.confidential.view' => 'เปิดหนังสือชั้นลับ',
        'saraban.secret.view' => 'เปิดหนังสือชั้นลับมาก',
        'saraban.top_secret.view' => 'เปิดหนังสือชั้นลับที่สุด',
        'saraban.report.view' => 'ดูรายงานงานสารบรรณ',
        'saraban.report.export' => 'ส่งออกรายงานงานสารบรรณ',
        'saraban.audit.view' => 'ดูประวัติการดำเนินการของหนังสือ',
        'saraban.policy.manage' => 'จัดการและเผยแพร่นโยบายงานสารบรรณ',
        'saraban.authority.view' => 'ดูตารางอำนาจหน้าที่',
        'saraban.config.manage' => 'ตั้งค่าทะเบียนหนังสือและหน่วยงานสารบรรณ',
        'saraban.exception.view' => 'ดูรายการข้อผิดพลาดที่ต้องตามแก้',
        'saraban.exception.manage' => 'มอบหมายและปิดรายการข้อผิดพลาด',
        'saraban.operations.view' => 'ดูศูนย์เฝ้าระวังการปฏิบัติการ',
        'saraban.operations.retry' => 'สั่งประมวลผลงานที่ล้มเหลวซ้ำ',
        'saraban.feature_flags.manage' => 'เปิด-ปิดความสามารถของโมดูลสารบรรณ',

        // ระบบกลาง
        'system.manage' => 'สิทธิ์ผู้ดูแลระบบสูงสุด',
        'system.user.view' => 'ดูรายการข้อมูลผู้ใช้งาน',
        'system.user.manage' => 'เพิ่ม/แก้ไข/ระงับผู้ใช้งานระบบ',
        'system.role.manage' => 'จัดการบทบาทและสิทธิ์การเข้าถึง',
        'system.menu.manage' => 'จัดการเมนูตามสิทธิ์',
        'system.setting.manage' => 'ตั้งค่าระบบและข้อมูลสถานศึกษา',
        'admin.users.manage_permissions' => 'กำหนดสิทธิ์รายบุคคล',
    ];

    /**
     * ชื่อชุดเดิมที่ยังถูกใช้จริงทั้งใน route, เมนู และการแจกสิทธิ์ของบทบาทที่มีอยู่
     *
     * **ยังลบไม่ได้** — ผู้ใช้ปัจจุบันถือชื่อชุดนี้ ไม่ใช่ชุดมาตรฐานด้านบน
     * การถอดออกต้องทำหลังย้ายการแจกสิทธิ์ของทุกบทบาทไปยังชื่อมาตรฐานครบแล้ว
     * (ดู `docs/audits/MENU-FEATURE-INVENTORY-2026-08-27.md` ข้อ D5)
     *
     * @var array<string,string>
     */
    public const LEGACY_PERMISSIONS = [
        'view_users' => 'ดูรายชื่อผู้ใช้งาน (ชื่อชุดเดิม)',
        'edit_users' => 'แก้ไขผู้ใช้งาน (ชื่อชุดเดิม)',
        'delete_users' => 'ลบผู้ใช้งาน (ชื่อชุดเดิม)',
        'manage_settings' => 'ตั้งค่าระบบ (ชื่อชุดเดิม)',
        'manage_departments' => 'จัดการหน่วยงาน (ชื่อชุดเดิม)',
        'manage_leave_types' => 'จัดการประเภทการลา (ชื่อชุดเดิม)',
        'manage_workflow_steps' => 'จัดการลำดับการอนุมัติ (ชื่อชุดเดิม)',
        'approve_leave' => 'อนุมัติใบลา (ชื่อชุดเดิม)',
        'view_all_attendances' => 'ดูการลงเวลาทั้งหมด (ชื่อชุดเดิม)',
        'export_reports' => 'ส่งออกรายงาน (ชื่อชุดเดิม)',
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::MODULE_PERMISSIONS + self::LEGACY_PERMISSIONS;
    }

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (array_keys(self::all()) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
