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
            'leave.view' => 'เข้าถึงหน้าการลา / ดูประวัติใบลาของตนเอง',
            'leave.create' => 'ยื่นใบลา',
            'leave.cancel' => 'ขอยกเลิกใบลา',
            'leave.approve' => 'อนุมัติการลา (ตามสายงาน)',
            'leave.hr_verify' => 'ตรวจสอบ / ยืนยันใบลา (ฝ่าย HR)',
            'leave.export' => 'ส่งออกรายงานการลา (PDF / Excel)',
        ],
    ],

    'attendance' => [
        'id' => 'attendance',
        'name' => 'ระบบลงเวลาออนไลน์ (e-Attendance)',
        'description' => 'บันทึกเวลาเข้า-ออกงาน ดูประวัติการลงเวลา และรายงานสรุปการปฏิบัติงาน',
        'icon' => 'clock',
        'permissions' => [
            'attendance.checkin' => 'บันทึกเวลาเข้า-ออกงาน (Check-in / Check-out)',
            'attendance.view_self' => 'ดูประวัติการลงเวลาของตนเอง',
            'attendance.view_all' => 'ดูรายงานการลงเวลาของบุคลากรทั้งหมด',
            'attendance.export' => 'ส่งออกรายงานเวลาเข้า-ออกงาน',
            'attendance.ot' => 'บันทึกเวลาทำงานล่วงเวลา (OT)',
            'attendance.ot_summary' => 'ดูสรุปการทำงานล่วงเวลา (OT Summary)',
        ],
    ],

    'saraban' => [
        'id' => 'saraban',
        'name' => 'ระบบสารบรรณอิเล็กทรอนิกส์ (e-Saraban)',
        'description' => 'งานรับ-ส่งหนังสือราชการ เดินเรื่องอนุมัติ และลงนามอิเล็กทรอนิกส์',
        'icon' => 'file-text',
        'permissions' => [
            'saraban.view' => 'เข้าถึงโมดูลสารบรรณ',
            'saraban.document.create' => 'สร้างหนังสือราชการ / เอกสารรับ-ส่ง',
            'saraban.document.view' => 'เข้าดูหนังสือราชการ',
            'saraban.document.approve' => 'ตรวจพิจารณา / อนุมัติเดินเรื่องหนังสือ',
            'saraban.document.sign' => 'ลงนาม ลายมือชื่ออิเล็กทรอนิกส์ (Digital Signature)',
            'saraban.document.dispatch' => 'ส่ง / เสนอ / เวียนหนังสือราชการ',
            'saraban.document.archive' => 'จัดเก็บ / เลิกใช้เอกสารสารบรรณ',
            'saraban.config.manage' => 'ตั้งค่าเลขที่หนังสือ / หมวดหมู่เอกสาร',
        ],
    ],

    'admin' => [
        'id' => 'admin',
        'name' => 'ระบบบริหารจัดการ (System Admin)',
        'description' => 'จัดการผู้ใช้งาน กำหนดสิทธิ์รายบุคคล และการตั้งค่าระบบหลัก',
        'icon' => 'settings',
        'permissions' => [
            'view_users' => 'ดูรายการผู้ใช้งานในระบบ',
            'edit_users' => 'เพิ่ม/แก้ไขข้อมูลผู้ใช้งาน',
            'delete_users' => 'ลบผู้ใช้งาน',
            'admin.users.manage_permissions' => 'จัดการสิทธิ์รายบุคคล (Individual Permissions)',
            'system.position.manage' => 'จัดการทะเบียนตำแหน่งและผูกตำแหน่งบุคลากร',
            'manage_departments' => 'จัดการฝ่าย / แผนก / โครงสร้างองค์กร',
            'system.org_structure.manage' => 'จัดการโครงสร้างหน่วยงานและแต่งตั้งหัวหน้าหน่วยงาน (กำหนดผู้อนุมัติ)',
            'manage_workflow_steps' => 'จัดการขั้นตอนการเดินเรื่องอนุมัติ',
            'manage_leave_types' => 'จัดการประเภทการลา',
            'manage_settings' => 'จัดการตั้งค่าระบบหลักและปีงบประมาณ',
        ],
    ],
];

return array_replace_recursive($modules, require __DIR__.'/module_registry.php');
