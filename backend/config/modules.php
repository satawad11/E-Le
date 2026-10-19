<?php

$modules = [
    /*
    |--------------------------------------------------------------------------
    | System Modules Registry & Permission Definitions
    |--------------------------------------------------------------------------
    |
    | Define all system modules, their metadata, and fine-grained permissions.
    | Standard naming convention for permissions: [module_id].[resource].[action]
    |
    */

    'leave' => [
        'id' => 'leave',
        'name' => 'ระบบการลาออนไลน์ (E-Leave)',
        'description' => 'ยื่นใบลา ตรวจสอบวันลาคงเหลือ อนุมัติและติดตามสถานะใบลา',
        'icon' => 'calendar',
        'permissions' => [
            // การยื่น/ยกเลิก/ดูใบลาของตนเองไม่ต้องใช้สิทธิ์ — ทุกบัญชีที่ใช้งานอยู่ทำได้
            'leave.approval.dept' => 'อนุมัติขั้นหัวหน้า และเห็นใบลาของหน่วยงานตามขอบเขต',
            'leave.approval.hr' => 'งานตรวจสอบเอกสารและหลักฐานการลา (งานบุคลากร)',
            'leave.management.manage' => 'จัดการวันลา บันทึกการลา/การลงนามแทน และทะเบียนการทำการแทน',
            'leave.report.view' => 'ดูรายงานและสถิติการลา',
            'leave.policy.manage' => 'จัดการนโยบายสิทธิการลา',
            'leave.calendar.manage' => 'จัดการปฏิทินวันหยุด/วันทำงาน',
            'leave.delete' => 'ลบใบลา (เฉพาะผู้ดูแลระบบสูงสุด — ปกติให้ใช้การยกเลิกวันลา)',
        ],
    ],

    'attendance' => [
        'id' => 'attendance',
        'name' => 'ระบบลงเวลาออนไลน์ (e-Attendance)',
        'description' => 'บันทึกเวลาเข้า-ออกงาน ดูประวัติการลงเวลา และรายงานสรุปการปฏิบัติงาน',
        'icon' => 'clock',
        'permissions' => [
            'attendance.checkin' => 'บันทึกเวลาเข้า-ออกงาน',
            'attendance.view_self' => 'ดูประวัติการลงเวลาของตนเอง',
            'attendance.view_all' => 'ดูรายงานการลงเวลาของบุคลากรทั้งหมด',
            'attendance.ot_summary' => 'ดูสรุปการทำงานล่วงเวลา — ดูอย่างเดียว ไม่อนุมัติ',
        ],
    ],

    'saraban' => [
        'id' => 'saraban',
        'name' => 'ระบบสารบรรณอิเล็กทรอนิกส์ (e-Saraban)',
        'description' => 'งานรับ-ส่งหนังสือราชการ เดินเรื่องอนุมัติ และลงนามอิเล็กทรอนิกส์',
        'icon' => 'file-text',
        'permissions' => [
            'saraban.document.view' => 'เข้าดูหนังสือราชการ',
            'saraban.document.create' => 'สร้างหนังสือราชการ / เอกสารรับ-ส่ง',
            'saraban.document.edit' => 'แก้ไขหนังสือราชการ',
            'saraban.document.register' => 'ลงทะเบียนรับ-ส่งหนังสือ (เจ้าหน้าที่สารบรรณ)',
            'saraban.document.approve' => 'ตรวจพิจารณา / อนุมัติเดินเรื่องหนังสือ',
            'saraban.document.sign' => 'ลงนามด้วยลายมือชื่ออิเล็กทรอนิกส์',
            'saraban.circular.broadcast' => 'ออกหนังสือเวียน',
            'saraban.document.report' => 'ดูรายงานทะเบียนหนังสือ',
            'saraban.document.view_classified' => 'เข้าถึงหนังสือชั้นความลับ',
            'saraban.document.delete' => 'ลบหนังสือราชการ',
            'saraban.document.manage_types' => 'จัดการประเภทหนังสือและสายงานอนุมัติ',
            'saraban.setting.manage' => 'ตั้งค่าระบบสารบรรณ',
        ],
    ],

    'admin' => [
        'id' => 'admin',
        'name' => 'ระบบบริหารจัดการ',
        'description' => 'จัดการผู้ใช้งาน กำหนดสิทธิ์รายบุคคล และการตั้งค่าระบบหลัก',
        'icon' => 'settings',
        'permissions' => [
            'system.user.view' => 'ดูรายการผู้ใช้งานในระบบ',
            'system.user.manage' => 'เพิ่ม/แก้ไขข้อมูลผู้ใช้งาน',
            'admin.users.manage_permissions' => 'จัดการสิทธิ์รายบุคคลและมอบบทบาท',
            'system.position.manage' => 'จัดการทะเบียนตำแหน่งและผูกตำแหน่งบุคลากร',
            'manage_departments' => 'จัดการฝ่าย / แผนก / โครงสร้างองค์กร',
            'system.org_structure.manage' => 'จัดการโครงสร้างหน่วยงานและแต่งตั้งหัวหน้าหน่วยงาน (กำหนดผู้อนุมัติ)',
            'manage_workflow_steps' => 'จัดการขั้นตอนการเดินเรื่องอนุมัติ',
            'manage_leave_types' => 'จัดการประเภทการลา',
            'system.setting.manage' => 'จัดการตั้งค่าระบบหลักและปีงบประมาณ',
            'system.menu.manage' => 'จัดการเมนู บทบาท และชุดสิทธิ์',
            'document.template.view' => 'ดูแบบฟอร์มเอกสาร',
            'document.template.manage' => 'ออกแบบและเผยแพร่แบบฟอร์มเอกสาร',
            'document.report.view' => 'ดูรายงานจากศูนย์จัดการเอกสาร',
            'document.report.manage' => 'ออกแบบรายงานในศูนย์จัดการเอกสาร',
            'document.final.download' => 'ดาวน์โหลดเอกสารฉบับสมบูรณ์',
        ],
    ],
];

return array_replace_recursive($modules, require __DIR__.'/module_registry.php');
