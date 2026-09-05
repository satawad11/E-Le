<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

/**
 * แหล่งความจริงเดียวของแถบเมนู
 *
 * CLEANUP-01 (D1) — ก่อนหน้านี้เมนูมีสองชุดที่ไม่ตรงกัน: ชุดที่ผู้ใช้เห็นจริงเขียน
 * ตายตัวอยู่ใน `frontend/src/App.vue` (และเขียนซ้ำอีกก๊อปสำหรับจอมือถือ) ส่วนชุดใน
 * ตาราง `menus` ที่ seeder และ migration หลายตัวช่วยกันเติม ชี้ไปยัง route ที่ไม่มีอยู่จริง
 * (`/dashboard`, `/calendar`, `/attendance/me`, `/admin/personnel-types`,
 * `/admin/leave-calendar`) หน้าจัดการเมนูจึงไม่มีผลกับสิ่งที่ผู้ใช้เห็นเลย
 *
 * ตอนนี้ตาราง `menus` เป็นแหล่งความจริงเดียว หน้าเว็บวาดแถบเมนูจาก `/api/user/menus`
 * และ `MenuIntegrityTest` บังคับว่าทุก path ในนี้ต้องมีอยู่จริงใน `router/index.js`
 *
 * กติกาการแก้ไข: เพิ่ม/แก้เมนูที่ `TREE` ที่เดียว แล้วรัน `php artisan db:seed
 * --class=MenuSeeder` — เมนูที่ไม่อยู่ใน `TREE` จะถูกลบทิ้งเพราะชี้ไปยังหน้าที่ไม่มีอยู่
 */
class MenuSeeder extends Seeder
{
    /**
     * โครงเมนูทั้งระบบ — ต้องตรงกับ `frontend/src/router/index.js`
     *
     * `permissions` = เห็นเมนูนี้ถ้ามีสิทธิ์ "อย่างน้อยหนึ่งข้อ" ในรายการ
     * รายการว่าง = ผู้ใช้ที่ล็อกอินแล้วเห็นได้ทุกคน
     * `module` = โมดูลเจ้าของ ถ้าโมดูลถูกปิด เมนูทั้งกิ่งจะหายไปเอง
     */
    public const TREE = [
        [
            'code' => 'dashboard',
            'title_th' => 'หน้าหลัก',
            'title_en' => 'Dashboard',
            'module' => 'system',
            'icon' => 'home',
            'route_name' => 'dashboard',
            'route_path' => '/',
            'permissions' => [],
        ],
        [
            'code' => 'leave',
            'title_th' => 'ระบบลางาน',
            'title_en' => 'Leave',
            'module' => 'leave',
            'icon' => 'calendar',
            'permissions' => [],
            'children' => [
                [
                    'code' => 'leave.request',
                    'title_th' => 'ยื่นใบลา',
                    'title_en' => 'Request Leave',
                    'route_name' => 'request',
                    'route_path' => '/request',
                    'permissions' => [],
                ],
                [
                    'code' => 'leave.my',
                    'title_th' => 'รายการใบลาของฉัน',
                    'title_en' => 'My Leaves',
                    'route_name' => 'my-leaves',
                    'route_path' => '/leaves',
                    'permissions' => [],
                ],
                [
                    'code' => 'leave.card',
                    'title_th' => 'บัตรลา',
                    'title_en' => 'Leave Card',
                    'route_name' => 'leave-card',
                    'route_path' => '/leave-card',
                    'permissions' => [],
                ],
                [
                    'code' => 'leave.team_calendar',
                    'title_th' => 'ปฏิทินวันลาทีม',
                    'title_en' => 'Team Calendar',
                    'route_name' => 'team-calendar',
                    'route_path' => '/team-calendar',
                    'permissions' => [],
                ],
                /*
                 * ทะเบียนคุมการลาอ่านข้อมูลของทั้งหน่วยงาน ฝั่ง server ปฏิเสธผู้ที่ไม่ถือ
                 * สิทธิ์รายงานอยู่แล้ว เมนูจึงต้องใช้เงื่อนไขเดียวกัน ไม่งั้นบุคลากรทั่วไป
                 * จะเห็นเมนูที่กดแล้วเด้ง 403 ทุกครั้ง
                 */
                [
                    'code' => 'leave.government_reports',
                    'title_th' => 'รายงานการลาราชการ',
                    'title_en' => 'Government Leave Reports',
                    'route_name' => 'reports',
                    'route_path' => '/reports',
                    'permissions' => ['leave.report.view', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.approvals',
                    'title_th' => 'อนุมัติใบลา',
                    'title_en' => 'Leave Approvals',
                    'route_name' => 'approvals',
                    'route_path' => '/approvals',
                    'permissions' => ['leave.approve', 'leave.approval.dept', 'leave.approval.hr'],
                ],
                [
                    'code' => 'leave.cancel_approvals',
                    'title_th' => 'อนุมัติยกเลิกใบลา',
                    'title_en' => 'Cancellation Approvals',
                    'route_name' => 'cancel-approvals',
                    'route_path' => '/cancel-approvals',
                    'permissions' => ['leave.approve', 'leave.approval.dept', 'leave.approval.hr'],
                ],
                [
                    'code' => 'leave.management',
                    'title_th' => 'จัดการ/ซิงค์วันลา (HR)',
                    'title_en' => 'Leave Management',
                    'route_name' => 'admin-leaves-management',
                    'route_path' => '/admin/leaves-management',
                    'permissions' => ['leave.approval.dept', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.promotion_screening',
                    'title_th' => 'คัดกรองเลื่อนเงินเดือน',
                    'title_en' => 'Promotion Screening',
                    'route_name' => 'admin-promotion-screening',
                    'route_path' => '/admin/promotion-screening',
                    'permissions' => ['leave.report.view', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.governance',
                    'title_th' => 'ศูนย์งานกำกับการลา',
                    'title_en' => 'Leave Governance',
                    'route_name' => 'admin-leave-governance',
                    'route_path' => '/admin/leave-governance',
                    'permissions' => ['leave.approval.hr', 'leave.management.manage'],
                ],
            ],
        ],
        [
            'code' => 'attendance',
            'title_th' => 'ระบบลงเวลาออนไลน์',
            'title_en' => 'Attendance',
            'module' => 'attendance',
            'icon' => 'clock',
            'permissions' => [],
            'children' => [
                [
                    'code' => 'attendance.checkin',
                    'title_th' => 'ลงเวลาปฏิบัติงาน',
                    'title_en' => 'Check In / Out',
                    'route_name' => 'attendance-check-in',
                    'route_path' => '/attendance/check-in',
                    'permissions' => ['attendance.checkin'],
                ],
                [
                    'code' => 'attendance.history',
                    'title_th' => 'ประวัติการลงเวลา',
                    'title_en' => 'My Attendance',
                    'route_name' => 'attendance-history',
                    'route_path' => '/attendance/history',
                    'permissions' => ['attendance.view_self'],
                ],
                [
                    'code' => 'attendance.records',
                    'title_th' => 'รายงานการลงเวลา',
                    'title_en' => 'Attendance Records',
                    'route_name' => 'admin-attendances',
                    'route_path' => '/admin/attendances',
                    'permissions' => ['attendance.view_all'],
                ],
                [
                    'code' => 'attendance.summary',
                    'title_th' => 'สรุปผลการปฏิบัติงาน',
                    'title_en' => 'Attendance Summary',
                    'route_name' => 'admin-attendances-summary',
                    'route_path' => '/admin/attendances/summary',
                    'permissions' => ['attendance.view_all'],
                ],
                [
                    'code' => 'overtime.orders',
                    'title_th' => 'คำสั่งปฏิบัติงานล่วงเวลา (OT)',
                    'title_en' => 'Overtime Orders',
                    'module' => 'overtime',
                    'route_name' => 'admin-overtime-orders',
                    'route_path' => '/admin/overtime-orders',
                    'permissions' => ['overtime.operation.execute', 'overtime.management.review'],
                ],
                [
                    'code' => 'overtime.summary',
                    'title_th' => 'สรุปผลการปฏิบัติงาน (OT)',
                    'title_en' => 'Overtime Summary',
                    'module' => 'overtime',
                    'route_name' => 'admin-ot-summary',
                    'route_path' => '/admin/ot-summary',
                    'permissions' => ['attendance.ot_summary', 'attendance.ot'],
                ],
            ],
        ],
        [
            'code' => 'reporting',
            'title_th' => 'รายงาน',
            'title_en' => 'Reports',
            'module' => 'reporting',
            'icon' => 'chart',
            'permissions' => [],
            'children' => [
                [
                    'code' => 'reporting.center',
                    'title_th' => 'ศูนย์รายงานกลาง',
                    'title_en' => 'Report Center',
                    'route_name' => 'report-center',
                    'route_path' => '/report-center',
                    'permissions' => [
                        'leave.report.view', 'system.user.view',
                        'attendance.view_all', 'overtime.management.review',
                    ],
                ],
            ],
        ],
        /*
         * งานสารบรรณ (INT-05)
         *
         * `module` = `saraban` — เมนูทั้งกิ่งหายเองเมื่อโมดูลถูกปิด โดย `MenuService`
         * ไม่ต้องเขียนเงื่อนไขเพิ่ม · สิทธิ์ที่ผูกไว้คือ "เห็นเมนูนี้ถ้ามีอย่างน้อยหนึ่งข้อ"
         * ส่วนการเข้าถึงจริงยังถูกตรวจซ้ำที่ฝั่ง server ทุกครั้ง (เมนูไม่ใช่ด่านความปลอดภัย)
         */
        [
            'code' => 'saraban',
            'title_th' => 'งานสารบรรณ',
            'title_en' => 'Saraban',
            'module' => 'saraban',
            'icon' => 'document',

            /*
             * INT-13 — หัวข้อนี้เปิดให้ผู้ถือสิทธิ์ใดสิทธิ์หนึ่งในสองข้อ
             *
             * ข้าราชการทั่วไปที่ได้รับหนังสือเวียนไม่มี `saraban.document.view`
             * (และไม่ควรมี เพราะนั่นคือสิทธิ์ค้นทะเบียนหนังสือของหน่วยงาน)
             * ถ้าหัวข้อผูกไว้กับข้อนั้นข้อเดียว กิ่งทั้งกิ่งจะหายไปจากเมนูของเขา
             * และหนังสือที่เวียนถึงเขาก็ไม่มีทางเปิดเจอ
             */
            'permissions' => [
                'saraban.document.view',
                'saraban.inbox.view',
                'saraban.document.create',
                'saraban.incoming.register',
                'saraban.report.view',
            ],
            'children' => [
                [
                    'code' => 'saraban.inbox',
                    'title_th' => 'หนังสือเวียนถึงฉัน',
                    'title_en' => 'My Inbox',
                    'route_name' => 'saraban-inbox',
                    'route_path' => '/saraban/inbox',
                    'permissions' => ['saraban.inbox.view'],
                ],
                /*
                 * คิวงานของผู้ใช้ (NAV §4.7)
                 *
                 * วางไว้ลำดับที่สองต่อจากกล่องหนังสือเวียน เพราะเป็นสองคำถามที่
                 * ผู้ใช้ถามทุกเช้า: "มีอะไรส่งถึงฉัน" กับ "มีอะไรรอฉันตัดสิน"
                 *
                 * ใช้สิทธิ์เดียวกับทะเบียนหนังสือ — ตัวที่กรองว่าใครเห็นอะไรจริง ๆ
                 * คือสายงานและ policy รายฉบับ ไม่ใช่สิทธิ์ระดับเมนู · ผู้ที่ไม่มี
                 * เรื่องรออยู่จะเห็นหน้าว่าง ซึ่งเป็นคำตอบที่ถูกต้อง
                 */
                [
                    'code' => 'saraban.queue',
                    'title_th' => 'รอฉันดำเนินการ',
                    'title_en' => 'Waiting for Me',
                    'route_name' => 'saraban-work-queue',
                    'route_path' => '/saraban/documents/queue',
                    'permissions' => ['saraban.document.view'],
                ],
                [
                    'code' => 'saraban.documents',
                    'title_th' => 'ทะเบียนหนังสือ',
                    'title_en' => 'Documents',
                    'route_name' => 'saraban-documents',
                    'route_path' => '/saraban/documents',
                    'permissions' => ['saraban.document.view'],
                ],
                [
                    'code' => 'saraban.documents.create',
                    'title_th' => 'สร้างหนังสือ',
                    'title_en' => 'New Document',
                    'route_name' => 'saraban-document-create',
                    'route_path' => '/saraban/documents/new',
                    'permissions' => ['saraban.document.create'],
                ],
                [
                    'code' => 'saraban.incoming',
                    'title_th' => 'ทะเบียนรับหนังสือ',
                    'title_en' => 'Incoming Register',
                    'route_name' => 'saraban-incoming',
                    'route_path' => '/saraban/incoming',
                    'permissions' => ['saraban.document.view'],
                ],
                [
                    'code' => 'saraban.incoming.register',
                    'title_th' => 'ลงทะเบียนรับ',
                    'title_en' => 'Register Incoming',
                    'route_name' => 'saraban-incoming-register',
                    'route_path' => '/saraban/incoming/new',
                    'permissions' => ['saraban.incoming.register'],
                ],
                [
                    // INT-18 — สารบบเรื่อง (SoT §22)
                    'code' => 'saraban.cases',
                    'title_th' => 'สารบบเรื่อง',
                    'title_en' => 'Cases',
                    'route_name' => 'saraban-cases',
                    'route_path' => '/saraban/cases',
                    'permissions' => ['saraban.document.view'],
                ],
                [
                    // INT-19 — รายงานทะเบียน (SoT §28)
                    'code' => 'saraban.reports',
                    'title_th' => 'รายงานทะเบียนงานสารบรรณ',
                    'title_en' => 'Register Reports',
                    'route_name' => 'saraban-reports',
                    'route_path' => '/saraban/reports',
                    'permissions' => ['saraban.report.view'],
                ],
            ],
        ],
        /*
         * การจัดการงานสารบรรณ — แยกจากงานประจำวันโดยเจตนา
         * (NAVIGATION-ACTION-PERMISSION-MATRIX §2, §5)
         *
         * **ทำไมเป็นกลุ่มระดับบนสุดแยก ไม่ใช่เมนูย่อยชั้นที่สาม**
         *
         * เอกสารต้นทางวาดผังไว้สามชั้น (สารบรรณ → การจัดการ → รายการย่อย) แต่
         * ระบบเมนูของโครงการนี้รองรับสองชั้น — `MenuSeeder::run()` วนแค่
         * `children` ชั้นเดียว · `MenuService` โหลด `children.permissions` ชั้นเดียว
         * · และ `AppMenuNav.vue` เรนเดอร์แค่ `item.children`
         *
         * การเพิ่มชั้นที่สามต้องแก้แกนกลางของระบบเมนู ซึ่งกระทบโมดูลการลา
         * การลงเวลา และการบริหารระบบทั้งหมด — เกินขอบเขตของการปรับเมนูโมดูลเดียว
         *
         * เอกสารต้นทางเองระบุไว้ใน §2 ว่า "อย่าสร้าง Sidebar ยาวเกินจำเป็น
         * ให้ใช้ Tabs/Secondary Navigation ในแต่ละหน้า" — การแยกเป็นสองกลุ่ม
         * ระดับบนสุดจึงตรงตามเจตนา และได้ผลเดียวกันคือ **แยกงานประจำวันออกจาก
         * งานตั้งค่า** โดยไม่แตะแกนกลาง
         *
         * `module` = `saraban` เหมือนกลุ่มแรก — ปิดโมดูลแล้วหายทั้งสองกลุ่ม
         */
        [
            'code' => 'saraban_admin',
            'title_th' => 'การจัดการงานสารบรรณ',
            'title_en' => 'Saraban Administration',
            'module' => 'saraban',
            'icon' => 'cog',

            /*
             * สิทธิ์ของกลุ่มคือ **ผลรวมของสิทธิ์ลูกทุกตัว**
             *
             * เมนูแม่กรองก่อนเมนูลูกเสมอ — ถ้าใส่ไม่ครบ ผู้ที่มีสิทธิ์เพียงข้อเดียว
             * เช่นดูตารางอำนาจอย่างเดียว จะไม่เห็นกลุ่มนี้เลยทั้งที่มีสิทธิ์เข้าหน้านั้น
             */
            'permissions' => [
                'saraban.authority.view',
                'saraban.workflow.simulate',
                'saraban.policy.manage',
                'saraban.config.manage',
                'saraban.operations.view',
                'saraban.report.view',
            ],
            'children' => [
                [
                    // INT-16 — SoT §6, §9, §18
                    'code' => 'saraban.authority',
                    'title_th' => 'ตารางอำนาจหน้าที่',
                    'title_en' => 'Authority Matrix',
                    'route_name' => 'saraban-authority',
                    'route_path' => '/saraban/authority',
                    'permissions' => ['saraban.authority.view'],
                ],
                [
                    'code' => 'saraban.workflow.simulate',
                    'title_th' => 'จำลองเส้นทางเดินหนังสือ',
                    'title_en' => 'Workflow Simulation',
                    'route_name' => 'saraban-workflow-simulate',
                    'route_path' => '/saraban/workflow/simulate',
                    'permissions' => ['saraban.workflow.simulate'],
                ],
                [
                    // INT-16 — ศูนย์นโยบาย (SoT §18)
                    'code' => 'saraban.policies',
                    'title_th' => 'ศูนย์นโยบายงานสารบรรณ',
                    'title_en' => 'Policy Center',
                    'route_name' => 'saraban-policies',
                    'route_path' => '/saraban/policies',
                    'permissions' => ['saraban.policy.manage'],
                ],
                [
                    // INT-16 — ตั้งค่าชุดเลขหนังสือ (SoT §9)
                    'code' => 'saraban.number_series',
                    'title_th' => 'ชุดเลขหนังสือ',
                    'title_en' => 'Number Series',
                    'route_name' => 'saraban-number-series',
                    'route_path' => '/saraban/number-series',
                    'permissions' => ['saraban.config.manage'],
                ],
                [
                    // INT-17 — SoT §19, §20, §21
                    'code' => 'saraban.operations',
                    'title_th' => 'ศูนย์เฝ้าระวังการปฏิบัติการ',
                    'title_en' => 'Command Center',
                    'route_name' => 'saraban-command-center',
                    'route_path' => '/saraban/operations',
                    'permissions' => ['saraban.operations.view'],
                ],
                [
                    // INT-20 — ทะเบียนหลักฐาน (SoT §23)
                    'code' => 'saraban.compliance',
                    'title_th' => 'ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด',
                    'title_en' => 'Compliance Evidence',
                    'route_name' => 'saraban-compliance',
                    'route_path' => '/saraban/compliance',
                    'permissions' => ['saraban.operations.view', 'saraban.report.view'],
                ],
            ],
        ],
        [
            'code' => 'users',
            'title_th' => 'จัดการผู้ใช้งาน',
            'title_en' => 'User Management',
            'module' => 'system',
            'icon' => 'users',
            'permissions' => [],
            'children' => [
                [
                    'code' => 'users.list',
                    'title_th' => 'รายชื่อผู้ใช้งาน',
                    'title_en' => 'Users',
                    'route_name' => 'admin-users',
                    'route_path' => '/admin/users',
                    'permissions' => ['system.user.view'],
                ],
                [
                    'code' => 'users.personnel_assignments',
                    'title_th' => 'กำหนดประเภทบุคลากร',
                    'title_en' => 'Personnel Assignments',
                    'route_name' => 'admin-personnel-assignments',
                    'route_path' => '/admin/personnel-assignments',
                    'permissions' => ['system.user.manage'],
                ],
                [
                    'code' => 'users.roles',
                    'title_th' => 'จัดการบทบาทและสิทธิ์',
                    'title_en' => 'Roles & Permissions',
                    'route_name' => 'admin-roles',
                    'route_path' => '/admin/roles',
                    'permissions' => ['system.menu.manage', 'system.role.manage'],
                ],
                [
                    'code' => 'users.menus',
                    'title_th' => 'จัดการเมนูตามสิทธิ์',
                    'title_en' => 'Menu Management',
                    'route_name' => 'admin-menus',
                    'route_path' => '/admin/menus',
                    'permissions' => ['system.menu.manage'],
                ],
            ],
        ],
        [
            'code' => 'settings',
            'title_th' => 'ตั้งค่าระบบ',
            'title_en' => 'System Settings',
            'module' => 'system',
            'icon' => 'cog',
            'permissions' => [],
            'children' => [
                [
                    'code' => 'settings.general',
                    'title_th' => 'ข้อมูลสถานศึกษาและแบบฟอร์ม',
                    'title_en' => 'Institution & Forms',
                    'route_name' => 'admin-settings',
                    'route_path' => '/admin/settings',
                    'permissions' => ['system.setting.manage'],
                ],
                [
                    'code' => 'settings.fiscal_years',
                    'title_th' => 'ปีงบประมาณ',
                    'title_en' => 'Fiscal Years',
                    'route_name' => 'admin-fiscal-years',
                    'route_path' => '/admin/fiscal-years',
                    'permissions' => ['system.setting.manage'],
                ],
                [
                    'code' => 'settings.work_calendar',
                    'title_th' => 'ปฏิทินวันทำการ',
                    'title_en' => 'Work Calendar',
                    'route_name' => 'admin-work-calendar',
                    'route_path' => '/admin/work-calendar',
                    'permissions' => ['system.setting.manage', 'leave.calendar.manage'],
                ],
                [
                    'code' => 'settings.departments',
                    'title_th' => 'จัดการแผนกวิชา',
                    'title_en' => 'Departments',
                    'route_name' => 'admin-departments',
                    'route_path' => '/admin/departments',
                    'permissions' => ['manage_departments'],
                ],
                [
                    'code' => 'settings.leave_policies',
                    'title_th' => 'นโยบายสิทธิการลา',
                    'title_en' => 'Leave Policies',
                    'module' => 'leave',
                    'route_name' => 'admin-leave-policies',
                    'route_path' => '/admin/leave-policies',
                    'permissions' => ['system.setting.manage', 'leave.policy.manage'],
                ],
                [
                    'code' => 'settings.leave_types',
                    'title_th' => 'ประเภทการลา',
                    'title_en' => 'Leave Types',
                    'module' => 'leave',
                    'route_name' => 'admin-leave-types',
                    'route_path' => '/admin/leave-types',
                    'permissions' => ['manage_leave_types'],
                ],
                [
                    'code' => 'settings.attendance',
                    'title_th' => 'ตั้งค่าระบบลงเวลา',
                    'title_en' => 'Attendance Settings',
                    'module' => 'attendance',
                    'route_name' => 'admin-attendance-settings',
                    'route_path' => '/admin/attendance-settings',
                    'permissions' => ['system.setting.manage'],
                ],
                [
                    'code' => 'settings.workflow',
                    'title_th' => 'จัดการลำดับการอนุมัติ',
                    'title_en' => 'Approval Workflow',
                    'route_name' => 'admin-workflow',
                    'route_path' => '/admin/workflow',
                    'permissions' => ['manage_workflow_steps'],
                ],
                [
                    'code' => 'settings.notifications',
                    'title_th' => 'การแจ้งเตือน',
                    'title_en' => 'Notifications',
                    'module' => 'notification',
                    'route_name' => 'admin-notifications',
                    'route_path' => '/admin/notifications',
                    'permissions' => ['system.setting.manage'],
                ],
                [
                    'code' => 'settings.document_center',
                    'title_th' => 'ศูนย์จัดการเอกสารและรายงาน',
                    'title_en' => 'Document & Report Center',
                    'route_name' => 'document-center',
                    'route_path' => '/admin/document-center',
                    'permissions' => [
                        'document.template.view', 'document.template.manage', 'system.setting.manage',
                    ],
                ],
                [
                    'code' => 'settings.modules',
                    'title_th' => 'โมดูลระบบ',
                    'title_en' => 'System Modules',
                    'route_name' => 'admin-modules',
                    'route_path' => '/admin/modules',
                    'permissions' => ['system.setting.manage'],
                ],
                [
                    // INT-15 — ปลายทาง webhook ขาออก (SoT §17)
                    'code' => 'settings.integrations',
                    'title_th' => 'การเชื่อมต่อระบบภายนอก',
                    'title_en' => 'Integrations',
                    'route_name' => 'admin-integrations',
                    'route_path' => '/admin/integrations',
                    'permissions' => ['system.setting.manage'],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $keptCodes = [];

        foreach (self::TREE as $order => $item) {
            $menu = $this->upsert($item, $order + 1, null);
            $keptCodes[] = $menu->code;

            foreach ($item['children'] ?? [] as $childOrder => $child) {
                // กิ่งลูกสืบทอดโมดูลจากกิ่งแม่ เว้นแต่ระบุเอง (เช่น OT อยู่ใต้กลุ่มลงเวลา)
                $child['module'] ??= $item['module'];
                $keptCodes[] = $this->upsert($child, $childOrder + 1, $menu->id)->code;
            }
        }

        /*
         * เมนูที่ไม่อยู่ใน TREE ชี้ไปยังหน้าที่ไม่มีอยู่จริง — เหลือไว้ก็มีแต่จะพาไป 404
         * (ของเดิมที่พบตอนสำรวจ: `leave.my` เดิมชี้ /leaves ผ่านชื่อ route ที่ไม่มี,
         * `admin.personnel_types` → /admin/personnel-types, `admin.leave_calendar`
         * → /admin/leave-calendar ซึ่งทั้งคู่ไม่เคยมีใน router)
         */
        Menu::query()->whereNotIn('code', $keptCodes)->delete();

        Cache::flush();
    }

    private function upsert(array $item, int $sortOrder, ?int $parentId): Menu
    {
        $permissionNames = $item['permissions'] ?? [];

        $menu = Menu::updateOrCreate(['code' => $item['code']], [
            'title_th' => $item['title_th'],
            'title_en' => $item['title_en'],
            'module' => $item['module'],
            'icon' => $item['icon'] ?? null,
            'route_name' => $item['route_name'] ?? null,
            'route_path' => $item['route_path'] ?? null,
            'component_path' => null,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'is_external' => false,
        ]);

        /*
         * สิทธิ์บางชื่อที่เมนูอ้างถึงยังไม่ถูกสร้างในบางระบบ (`leave.management.manage`,
         * `system.role.manage` มีนิยามอยู่ใน StandardRolesAndPermissionsSeeder ที่ไม่ได้
         * ถูกเรียกตอนติดตั้ง) ถ้าไม่สร้างให้ที่นี่ เงื่อนไขของเมนูจะขาดไปเงียบ ๆ
         * การสร้างชื่อสิทธิ์ไม่ได้แจกสิทธิ์ให้ใคร จึงไม่กระทบขอบเขตของผู้ใช้เดิม
         */
        $permissionIds = collect($permissionNames)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->id)
            ->all();

        $menu->permissions()->sync($permissionIds);

        return $menu;
    }
}
