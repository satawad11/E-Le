<?php

use App\Providers\Modules\AttendanceModuleServiceProvider;
use App\Providers\Modules\LeaveModuleServiceProvider;
use App\Providers\Modules\NotificationModuleServiceProvider;
use App\Providers\Modules\SarabanModuleServiceProvider;

return [
    'admin' => [
        'code' => 'system',
        'name_th' => 'ระบบกลางและการบริหารระบบ',
        'name_en' => 'Core & Administration',
        'version' => '1.0.0',
        'enabled' => true,
        'can_disable' => false,
        'dependencies' => [],
        'order' => 0,
        'routes' => [],
        'route_files' => [],
        'menus' => ['system'],
        'policies' => [],
        'workflow_definitions' => [],
        'settings' => ['system'],
        'notifications' => [],
        'reports' => [],
        'audit_events' => ['user.created', 'user.updated', 'user.deleted'],
        'providers' => [],
        'route_patterns' => [],
        'legacy_permissions' => ['view_users', 'edit_users', 'delete_users', 'admin.users.manage_permissions', 'manage_departments', 'manage_workflow_steps', 'manage_leave_types', 'manage_settings'],
    ],
    'leave' => [
        'code' => 'leave',
        'name_th' => 'ระบบการลา',
        'name_en' => 'Leave',
        'version' => '1.0.0',
        'enabled' => env('MODULE_LEAVE_ENABLED', true),
        'dependencies' => ['system'],
        'order' => 10,
        'routes' => ['leaves', 'approvals', 'government-reports'],
        'route_files' => [],
        'menus' => ['leave'],
        'policies' => ['leave'],
        'workflow_definitions' => ['leave.approval'],
        'settings' => ['leave'],
        'notifications' => ['leave.submitted', 'leave.approval_requested', 'leave.approved', 'leave.rejected', 'leave.returned'],
        'reports' => ['leave', 'leave_balance'],
        'audit_events' => ['leave.created', 'leave.updated', 'leave.cancelled', 'leave.approved', 'leave.rejected'],
        'providers' => [LeaveModuleServiceProvider::class],
        'route_patterns' => ['api/leaves*', 'api/approvals*', 'api/admin/leaves*', 'api/reports/summary', 'api/reports/export-*', 'api/reports/leave-card*', 'api/reports/promotion-screening*', 'api/reports/government-leave*'],
        'legacy_permissions' => ['leave.view', 'leave.create', 'leave.cancel', 'leave.approve', 'leave.hr_verify', 'leave.export'],
    ],
    'attendance' => [
        'code' => 'attendance',
        'name_th' => 'ระบบลงเวลา',
        'name_en' => 'Attendance',
        'version' => '1.0.0',
        'enabled' => env('MODULE_ATTENDANCE_ENABLED', true),
        'dependencies' => ['system'],
        'order' => 20,
        'routes' => ['attendances', 'attendance-corrections'],
        'route_files' => [],
        'menus' => ['attendance'],
        'policies' => [],
        'workflow_definitions' => ['attendance.correction'],
        'settings' => ['attendance'],
        'notifications' => ['attendance.correction', 'attendance.late', 'attendance.early_leave', 'attendance.leave_conflict'],
        'reports' => ['attendance', 'late', 'absence'],
        'audit_events' => ['attendance.created', 'attendance.updated', 'attendance.deleted', 'attendance.correction.approved'],
        'providers' => [AttendanceModuleServiceProvider::class],
        'route_patterns' => ['api/attendances*', 'api/admin/attendances*', 'api/admin/attendance-corrections*'],
        'legacy_permissions' => ['attendance.checkin', 'attendance.view_self', 'attendance.view_all', 'attendance.export', 'attendance.ot', 'attendance.ot_summary'],
    ],
    'overtime' => [
        'id' => 'overtime',
        'code' => 'overtime',
        'name' => 'ระบบงานล่วงเวลา',
        'name_th' => 'ระบบงานล่วงเวลา',
        'name_en' => 'Overtime',
        'description' => 'คำสั่ง มอบหมาย บันทึกเวลาจริง และรับรองเวลาปฏิบัติงานล่วงเวลา',
        'icon' => 'clock',
        'version' => '1.0.0',
        'enabled' => env('MODULE_OVERTIME_ENABLED', true),
        'dependencies' => ['system', 'attendance'],
        'order' => 25,
        'routes' => ['overtime'],
        'route_files' => [],
        'menus' => [],
        'permissions' => [
            'overtime.operation.execute' => 'บันทึกและจัดทำคำสั่งปฏิบัติงานล่วงเวลา',
            'overtime.management.review' => 'อนุมัติ รับรอง และดูรายงานการปฏิบัติงานล่วงเวลา',
        ],
        'policies' => [],
        'workflow_definitions' => ['overtime.order'],
        'settings' => [],
        'notifications' => ['overtime.approval'],
        'reports' => ['ot'],
        'audit_events' => ['overtime.order.created', 'overtime.order.approved', 'overtime.time.certified'],
        'providers' => [],
        'route_patterns' => ['api/overtime*', 'api/attendances/ot-*', 'api/admin/attendances/ot-summary'],
        'legacy_permissions' => [],
    ],
    /*
     * INT-02 — โมดูลงานสารบรรณอิเล็กทรอนิกส์
     *
     * `dependencies` มีแค่ `system` โดยเจตนา แม้โมดูลนี้จะส่งการแจ้งเตือน
     * เพราะศูนย์แจ้งเตือนเป็นโครงสร้างพื้นฐานที่ "ไม่มีก็ยังทำงานได้"
     * (`NotificationCenter::emit()` คืน null เงียบ ๆ เมื่อโมดูลแจ้งเตือนถูกปิด)
     * ถ้าประกาศว่าพึ่งพา การปิดศูนย์แจ้งเตือนจะทำให้ลงทะเบียนรับหนังสือไม่ได้ทั้งระบบ
     * ซึ่งขัดกับ SoT §16 ที่ห้ามให้ธุรกรรมล้มเพราะช่องทางแจ้งเตือนล้ม
     * โมดูลการลาและลงเวลาก็ประกาศไว้แบบเดียวกันทั้งที่ส่งแจ้งเตือนเหมือนกัน
     *
     * `menus` ยังว่างโดยเจตนา — `MenuIntegrityTest` บังคับสองทางว่าทุกเมนูต้องมีหน้าจอจริง
     * ใน `frontend/src/router/index.js` และทุกหน้าที่ต้องล็อกอินต้องมีเมนูชี้ถึง
     * เมนูของสารบรรณจึงต้องมาพร้อมหน้าจอจริง ไม่ใช่มาก่อน
     */
    'saraban' => [
        'id' => 'saraban',
        'code' => 'saraban',
        'name' => 'ระบบงานสารบรรณอิเล็กทรอนิกส์',
        'name_th' => 'ระบบงานสารบรรณอิเล็กทรอนิกส์',
        'name_en' => 'Saraban (Correspondence)',
        'description' => 'ทะเบียนหนังสือรับ-ส่ง หนังสือภายใน การเดินเรื่อง เกษียนหนังสือ ลงนาม และกระจายหนังสือ',
        'icon' => 'document',
        'version' => '1.0.0',
        'enabled' => env('MODULE_SARABAN_ENABLED', true),
        'dependencies' => ['system'],
        'order' => 30,
        'routes' => [],
        'route_files' => [],
        'menus' => [],
        'permissions' => [
            // เอกสารและทะเบียนหนังสือ (SoT §8)
            'saraban.document.view' => 'ดูหนังสือและทะเบียนเอกสาร',
            'saraban.document.create' => 'สร้างหนังสือ',
            'saraban.document.update' => 'แก้ไขหนังสือที่ยังไม่ออกเลข',
            'saraban.document.submit' => 'เสนอหนังสือเข้าสู่กระบวนการ',

            // ทะเบียนรับ (SoT §10)
            'saraban.incoming.register' => 'ลงทะเบียนรับหนังสือ',
            'saraban.incoming.stamp' => 'ประทับตรารับหนังสือ',

            // เลขหนังสือ (SoT §9)
            'saraban.number.reserve' => 'จองเลขหนังสือ',
            'saraban.number.allocate' => 'ออกเลขหนังสือ',
            'saraban.number.release' => 'คืนเลขหนังสือที่จองไว้',
            'saraban.number.cancel' => 'ยกเลิกเลขหนังสือ',

            // การเดินเรื่อง (SoT §11, §12)
            'saraban.workflow.review' => 'ตรวจและดำเนินการหนังสือตามสายงาน',
            'saraban.workflow.forward' => 'ส่งหนังสือต่อไปยังขั้นถัดไป',
            'saraban.workflow.return' => 'ส่งหนังสือกลับไปแก้ไข',
            'saraban.workflow.simulate' => 'จำลองเส้นทางเดินหนังสือ',
            'saraban.workflow.manage' => 'จัดการและเผยแพร่เวอร์ชันสายงานเดินหนังสือ',

            // การเกษียนและลงนาม (SoT §14)
            'saraban.endorse.create' => 'เกษียนหนังสือ',
            'saraban.signature.sign' => 'ลงนามในหนังสือ',

            // การกระจายหนังสือ (SoT §15)
            'saraban.distribution.create' => 'จัดทำรายการกระจายหนังสือ',
            'saraban.distribution.send' => 'ส่งหนังสือถึงผู้รับ',
            'saraban.distribution.track' => 'ติดตามการรับทราบและการดำเนินการ',

            /*
             * INT-13 — เปิดกล่องหนังสือเวียนถึงตนเอง
             *
             * แยกจาก `saraban.document.view` เพราะเป็นคนละคำถาม: ข้อนั้นคือ
             * "ค้นทะเบียนหนังสือของหน่วยงานได้" ส่วนข้อนี้คือ "เปิดหนังสือที่เวียน
             * ถึงตนได้" — ข้าราชการส่วนใหญ่ต้องได้ข้อหลังโดยไม่ควรได้ข้อแรก
             */
            'saraban.inbox.view' => 'เปิดกล่องหนังสือเวียนถึงตนเอง',

            /*
             * ชั้นความลับ (SoT §18)
             *
             * **เป็นด่านเพิ่ม ไม่ใช่ด่านแทน** — ต้องมี `saraban.document.view`
             * และอยู่ในขอบเขตหน่วยงานอยู่ก่อนแล้ว สิทธิ์สองข้อนี้เพียงเปิดให้
             * เห็นหนังสือชั้นที่สูงขึ้นภายในขอบเขตเดิมเท่านั้น
             *
             * การจับคู่ชั้น→สิทธิ์ **ไม่ได้ฝังในโค้ด** อยู่ในนโยบาย `confidentiality`
             * ที่แก้และย้อนกลับได้จากศูนย์นโยบาย
             */
            'saraban.confidential.view' => 'เปิดหนังสือชั้นลับ',
            'saraban.secret.view' => 'เปิดหนังสือชั้นลับมาก',
            'saraban.top_secret.view' => 'เปิดหนังสือชั้นลับที่สุด',

            // รายงานและการตรวจสอบ (SoT §25, §28)
            'saraban.report.view' => 'ดูรายงานงานสารบรรณ',
            'saraban.report.export' => 'ส่งออกรายงานงานสารบรรณ',
            'saraban.audit.view' => 'ดูประวัติการดำเนินการของหนังสือ',

            // การกำกับดูแล (SoT §13, §18)
            'saraban.policy.manage' => 'จัดการและเผยแพร่นโยบายงานสารบรรณ',
            'saraban.authority.view' => 'ดูตารางอำนาจหน้าที่',
            'saraban.config.manage' => 'ตั้งค่าทะเบียนหนังสือและหน่วยงานสารบรรณ',

            // การปฏิบัติการ (SoT §19, §20, §21)
            'saraban.exception.view' => 'ดูรายการข้อผิดพลาดที่ต้องตามแก้',
            'saraban.exception.manage' => 'มอบหมายและปิดรายการข้อผิดพลาด',
            'saraban.operations.view' => 'ดูศูนย์เฝ้าระวังการปฏิบัติการ',
            'saraban.operations.retry' => 'สั่งประมวลผลงานที่ล้มเหลวซ้ำ',
            'saraban.feature_flags.manage' => 'เปิด-ปิดความสามารถของโมดูลสารบรรณ',
        ],
        'policies' => ['saraban_document'],
        'workflow_definitions' => [],
        'settings' => [],
        'notifications' => ['saraban.document.distributed'],
        'reports' => [],
        'audit_events' => [
            'saraban.document.created', 'saraban.document.updated', 'saraban.document.submitted',
            'saraban.distribution.sent', 'saraban.distribution.acknowledged', 'saraban.distribution.progress_reported',
        ],
        'providers' => [SarabanModuleServiceProvider::class],
        // INT-15 — พื้นผิว v1 ชี้ไปยัง controller ชุดเดียวกัน จึงต้องถูกปิดพร้อมกันเมื่อโมดูลถูกปิด
        'route_patterns' => ['api/saraban*', 'api/v1/saraban*'],
        'legacy_permissions' => [],
    ],
    'notification' => [
        'id' => 'notification',
        'code' => 'notification',
        'name' => 'ศูนย์การแจ้งเตือน',
        'name_th' => 'ศูนย์การแจ้งเตือน',
        'name_en' => 'Notification Center',
        'description' => 'การแจ้งเตือน In-App, Email และตัวเชื่อมต่อภายนอก',
        'icon' => 'bell',
        'version' => '1.0.0',
        'enabled' => env('MODULE_NOTIFICATION_ENABLED', true),
        'dependencies' => ['system'],
        'order' => 40,
        'routes' => ['notifications'],
        'route_files' => [],
        'menus' => [],
        'permissions' => [],
        'policies' => [],
        'workflow_definitions' => [],
        'settings' => ['notification_channels'],
        'notifications' => ['*'],
        'reports' => [],
        'audit_events' => ['notification.preference.updated'],
        'providers' => [NotificationModuleServiceProvider::class],
        'route_patterns' => ['api/notifications*', 'api/notification-preferences*'],
        'legacy_permissions' => [],
    ],
    'reporting' => [
        'id' => 'reporting',
        'code' => 'reporting',
        'name' => 'ศูนย์รายงานและข้อมูลประเมิน',
        'name_th' => 'ศูนย์รายงานและข้อมูลประเมิน',
        'name_en' => 'Reporting & Evaluation Data',
        'description' => 'รายงานกลางที่ใช้สิทธิ์และขอบเขตของโมดูลต้นทาง',
        'icon' => 'chart',
        'version' => '1.0.0',
        'enabled' => env('MODULE_REPORTING_ENABLED', true),
        'dependencies' => ['system'],
        'order' => 50,
        'routes' => ['reporting'],
        'route_files' => [],
        'menus' => [],
        'permissions' => [],
        'policies' => [],
        'workflow_definitions' => [],
        'settings' => [],
        'notifications' => [],
        'reports' => ['personnel', 'leave', 'leave_balance', 'attendance', 'late', 'absence', 'ot', 'executive'],
        'audit_events' => ['report.exported'],
        'providers' => [],
        'route_patterns' => ['api/reporting*'],
        'legacy_permissions' => [],
    ],
];
