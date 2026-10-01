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
        /*
         * ระบบลางาน (Leave System) — เรียงลำดับ: งานตนเอง ➔ งานอนุมัติ ➔ กำกับดูแล/รายงาน
         */
        [
            'code' => 'leave',
            'title_th' => 'ระบบลางาน',
            'title_en' => 'Leave Management',
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
                    'code' => 'leave.government_reports',
                    'title_th' => 'รายงานการลาราชการ',
                    'title_en' => 'Government Leave Reports',
                    'route_name' => 'reports',
                    'route_path' => '/reports',
                    'permissions' => ['leave.report.view', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.governance',
                    'title_th' => 'ตรวจหลักฐานการลา',
                    'title_en' => 'Leave Evidence Review',
                    'route_name' => 'admin-leave-governance',
                    'route_path' => '/admin/leave-governance',
                    'permissions' => ['leave.approval.hr', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.management',
                    'title_th' => 'จัดการข้อมูลการลา',
                    'title_en' => 'Leave Management',
                    'route_name' => 'admin-leaves-management',
                    'route_path' => '/admin/leaves-management',
                    'permissions' => ['leave.approval.dept', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.promotion_screening',
                    'title_th' => 'เกณฑ์เลื่อนเงินเดือน',
                    'title_en' => 'Promotion Screening',
                    'route_name' => 'admin-promotion-screening',
                    'route_path' => '/admin/promotion-screening',
                    'permissions' => ['leave.report.view', 'leave.management.manage'],
                ],
                [
                    'code' => 'leave.vocational_evaluation',
                    'title_th' => 'รายงานประเมิน ก.ค.ศ. (เกณฑ์ 23 วัน)',
                    'title_en' => 'Vocational Evaluation Report',
                    'route_name' => 'vocational-evaluation-report',
                    'route_path' => '/reports/vocational-evaluation',
                    'permissions' => ['leave.report.view', 'leave.management.manage'],
                ],
            ],
        ],
        /*
         * ระบบลงเวลาออนไลน์ (Attendance & Overtime) — เรียงลำดับ: ลงเวลาตนเอง ➔ บันทึกรวม ➔ ล่วงเวลา OT
         */
        [
            'code' => 'attendance',
            'title_th' => 'ระบบลงเวลาออนไลน์',
            'title_en' => 'Attendance System',
            'module' => 'attendance',
            'icon' => 'clock',
            'permissions' => [
                'attendance.checkin',
                'attendance.view_self',
                'attendance.view_all',
                'overtime.operation.execute',
                'overtime.management.review',
                'attendance.ot_summary',
                'attendance.ot',
            ],
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
                    'title_th' => 'การลงเวลาของฉัน',
                    'title_en' => 'My Attendance',
                    'route_name' => 'attendance-history',
                    'route_path' => '/attendance/history',
                    'permissions' => ['attendance.view_self'],
                ],
                [
                    'code' => 'attendance.records',
                    'title_th' => 'การลงเวลาบุคลากร',
                    'title_en' => 'Staff Attendance',
                    'route_name' => 'admin-attendances',
                    'route_path' => '/admin/attendances',
                    'permissions' => ['attendance.view_all'],
                ],
                [
                    'code' => 'attendance.summary',
                    'title_th' => 'สรุปเวลาปฏิบัติงาน',
                    'title_en' => 'Attendance Summary',
                    'route_name' => 'admin-attendances-summary',
                    'route_path' => '/admin/attendances/summary',
                    'permissions' => ['attendance.view_all'],
                ],
                [
                    'code' => 'attendance.biometric_hub',
                    'title_th' => 'ศูนย์นำเข้าไฟล์สแกนเวลา',
                    'title_en' => 'Biometric Hub',
                    'route_name' => 'admin-biometric-hub',
                    'route_path' => '/admin/attendance/biometric-hub',
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
                    'title_th' => 'สรุปเวลาล่วงเวลา (OT)',
                    'title_en' => 'Overtime Summary',
                    'module' => 'overtime',
                    'route_name' => 'admin-ot-summary',
                    'route_path' => '/admin/ot-summary',
                    'permissions' => ['attendance.ot_summary', 'attendance.ot'],
                ],
            ],
        ],
        /*
         * งานสารบรรณ (INT-05) — รวมศูนย์ตาม Flow หนังสือราชการ
         */
        [
            'code' => 'saraban',
            'title_th' => 'งานสารบรรณ',
            'title_en' => 'Saraban',
            'module' => 'saraban',
            'icon' => 'document',
            'permissions' => [
                'saraban.document.view',
                'saraban.inbox.view',
                'saraban.document.create',
                'saraban.incoming.register',
                'saraban.number.reserve',
                'saraban.distribution.track',
                'saraban.report.view',
            ],
            'children' => [
                [
                    'code' => 'saraban.dashboard',
                    'title_th' => 'แดชบอร์ด',
                    'title_en' => 'Dashboard',
                    'route_name' => 'saraban-dashboard',
                    'route_path' => '/saraban',
                    'permissions' => ['saraban.document.view'],
                ],
                [
                    'code' => 'saraban.inbox',
                    'title_th' => 'กล่องงานและหนังสือของฉัน',
                    'title_en' => 'My Tasks & Inbox',
                    'route_name' => 'saraban-inbox',
                    'route_path' => '/saraban/inbox',
                    'permissions' => ['saraban.inbox.view', 'saraban.document.view'],
                ],
                [
                    'code' => 'saraban.registers',
                    'title_th' => 'สมุดทะเบียนสารบรรณ',
                    'title_en' => 'Official Registers',
                    'route_name' => 'saraban-registers',
                    'route_path' => '/saraban/registers',
                    'permissions' => ['saraban.document.view', 'saraban.incoming.register', 'saraban.number.reserve'],
                ],
                [
                    'code' => 'saraban.documents.create',
                    'title_th' => 'สร้างหนังสือราชการ',
                    'title_en' => 'New Document',
                    'route_name' => 'saraban-document-create',
                    'route_path' => '/saraban/documents/new',
                    'permissions' => ['saraban.document.create'],
                ],
                [
                    'code' => 'saraban.archive',
                    'title_th' => 'คลังเอกสารและสารบบเรื่อง',
                    'title_en' => 'Archive & Dockets',
                    'route_name' => 'saraban-archive',
                    'route_path' => '/saraban/archive',
                    'permissions' => ['saraban.document.view'],
                ],
                [
                    'code' => 'saraban.distribution',
                    'title_th' => 'กระจายหนังสือและหนังสือเวียน',
                    'title_en' => 'Distribution',
                    'route_name' => 'saraban-distributions',
                    'route_path' => '/saraban/distributions',
                    'permissions' => ['saraban.distribution.track'],
                ],
                [
                    'code' => 'saraban.reports',
                    'title_th' => 'รายงานและสถิติสารบรรณ',
                    'title_en' => 'Saraban Reports',
                    'route_name' => 'saraban-reports',
                    'route_path' => '/saraban/reports',
                    'permissions' => ['saraban.report.view'],
                ],
            ],
        ],
        /*
         * การจัดการงานสารบรรณ — สำหรับเจ้าหน้าที่สารบรรณ/ผู้ดูแล
         */
        [
            'code' => 'saraban_admin',
            'title_th' => 'การจัดการงานสารบรรณ',
            'title_en' => 'Saraban Administration',
            'module' => 'saraban',
            'icon' => 'cog',
            'permissions' => [
                'saraban.authority.view',
                'saraban.workflow.manage',
                'saraban.workflow.simulate',
                'saraban.policy.manage',
                'saraban.config.manage',
                'saraban.report.view',
            ],
            'children' => [
                [
                    'code' => 'saraban.admin_hub',
                    'title_th' => 'ศูนย์จัดการและตั้งค่าระบบสารบรรณ',
                    'title_en' => 'Saraban Administration Hub',
                    'route_name' => 'saraban-admin-hub',
                    'route_path' => '/saraban/admin',
                    'permissions' => [
                        'saraban.config.manage',
                        'saraban.workflow.manage',
                        'saraban.workflow.simulate',
                        'saraban.authority.view',
                        'saraban.policy.manage',
                    ],
                ],
            ],
        ],
        /*
         * ศูนย์รายงานและสถิติ (Reports & Analytics)
         */
        [
            'code' => 'reporting',
            'title_th' => 'รายงาน',
            'title_en' => 'Reports',
            'module' => 'reporting',
            'icon' => 'chart',
            'permissions' => [
                'leave.report.view',
                'system.user.view',
                'attendance.view_all',
                'overtime.management.review',
                'document.report.view',
                'document.report.manage',
                'system.setting.manage',
            ],
            'children' => [
                [
                    'code' => 'reporting.center',
                    'title_th' => 'ศูนย์รายงานกลาง',
                    'title_en' => 'Report Center',
                    'route_name' => 'report-center',
                    'route_path' => '/report-center',
                    'permissions' => [
                        'leave.report.view',
                        'system.user.view',
                        'attendance.view_all',
                        'overtime.management.review',
                    ],
                ],
                [
                    'code' => 'reporting.custom',
                    'title_th' => 'รายงานที่ออกแบบเอง',
                    'title_en' => 'Custom Reports',
                    'route_name' => 'custom-reports',
                    'route_path' => '/reports/custom',
                    'permissions' => ['document.report.view', 'document.report.manage', 'system.setting.manage'],
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
                    'title_th' => 'รายชื่อบุคลากร',
                    'title_en' => 'Staff Directory',
                    'route_name' => 'admin-users',
                    'route_path' => '/admin/users',
                    'permissions' => ['system.user.view'],
                ],
                [
                    'code' => 'users.personnel_assignments',
                    'title_th' => 'ประเภทบุคลากร',
                    'title_en' => 'Personnel Types',
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
                    // ผังองค์กรเหลือชุดเดียว — หน้านี้จัดการ `org_units` โดยตรง
                    'code' => 'settings.departments',
                    'title_th' => 'โครงสร้างหน่วยงาน',
                    'title_en' => 'Org Units',
                    'route_name' => 'admin-org-units',
                    'route_path' => '/admin/org-units',
                    'permissions' => ['manage_departments'],
                ],
                [
                    'code' => 'settings.org_unit_managers',
                    'title_th' => 'ผู้รับผิดชอบหน่วยงาน',
                    'title_en' => 'Org Unit Managers',
                    'route_name' => 'admin-org-unit-managers',
                    'route_path' => '/admin/org-unit-managers',
                    'permissions' => ['manage_departments'],
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
                    // รายงานย้ายไปหมวด "รายงาน" แล้ว หน้านี้เหลือเฉพาะแบบฟอร์มและเอกสาร
                    'code' => 'settings.document_center',
                    'title_th' => 'ศูนย์จัดการเอกสาร',
                    'title_en' => 'Document Center',
                    'route_name' => 'document-center',
                    'route_path' => '/admin/document-center',
                    'permissions' => [
                        'document.template.view', 'document.template.manage', 'system.setting.manage',
                    ],
                ],
                [
                    'code' => 'users.menus',
                    'title_th' => 'สิทธิ์การเข้าถึงเมนู',
                    'title_en' => 'Menu Access',
                    'route_name' => 'admin-menus',
                    'route_path' => '/admin/menus',
                    'permissions' => ['system.menu.manage'],
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
                    // ศูนย์ปฏิบัติการระบบ — เดิมอยู่ใต้งานสารบรรณ ย้ายมาเมื่อถอดโมดูลสารบรรณ
                    'code' => 'settings.operations',
                    'title_th' => 'ศูนย์ปฏิบัติการระบบ',
                    'title_en' => 'Operations Center',
                    'route_name' => 'admin-operations',
                    'route_path' => '/admin/operations',
                    'permissions' => ['system.operations.view', 'system.exception.view'],
                ],
                [
                    'code' => 'settings.compliance',
                    'title_th' => 'หลักฐานตามระเบียบ',
                    'title_en' => 'Compliance Evidence',
                    'route_name' => 'admin-compliance',
                    'route_path' => '/admin/operations/compliance',
                    'permissions' => ['system.operations.view'],
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
