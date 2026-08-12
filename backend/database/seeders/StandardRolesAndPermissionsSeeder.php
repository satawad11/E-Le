<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class StandardRolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Standard Dot Notation Permissions grouped by module
        $permissionGroups = [
            'leave' => [
                'leave.request.create' => 'ยื่นใบลาออนไลน์',
                'leave.request.view_my' => 'ดูประวัติการลาของตนเอง',
                'leave.request.cancel' => 'ขอยกเลิกใบลา',
                'leave.approval.dept' => 'อนุมัติการลาระดับแผนกวิชา/ฝ่าย',
                'leave.approval.final' => 'อนุมัติการลาขั้นสุดท้าย (ผอ.)',
                'leave.report.view' => 'ดูรายงานและสถิติการลา',
                'leave.management.manage' => 'จัดการสิทธิ์วันลาและซิงค์ข้อมูลวันลา (HR)',
            ],
            // === Attendance — แยกโมดูลออกจาก Leave ===
            'attendance' => [
                'attendance.checkin' => 'บันทึกเวลาเข้า-ออกงาน (Check-in / Check-out)',
                'attendance.view_self' => 'ดูประวัติการลงเวลาของตนเอง',
                'attendance.view_all' => 'ดูรายงานการลงเวลาของบุคลากรทั้งหมด',
                'attendance.export' => 'ส่งออกรายงานเวลาเข้า-ออกงาน',
                'attendance.ot' => 'บันทึกเวลาทำงานล่วงเวลา (OT)',
                'attendance.ot_summary' => 'ดูสรุปการทำงานล่วงเวลา (OT Summary)',
                'attendance.record.create' => 'สร้างการบันทึกเวลาปฏิบัติงาน',
                'attendance.record.view_my' => 'ดูประวัติการบันทึกเวลาปฏิบัติงานของตนเอง',
            ],
            'saraban' => [
                'saraban.document.view' => 'เข้าถึงทะเบียนรับ-ส่งหนังสือราชการ',
                'saraban.document.create' => 'สร้างร่างหนังสือราชการใหม่',
                'saraban.document.edit' => 'แก้ไขเนื้อหาหนังสือราชการ',
                'saraban.document.delete' => 'ลบหนังสือราชการ',
                'saraban.document.approve' => 'กลั่นกรอง/เสนออนุมัติหนังสือราชการ',
                'saraban.document.sign' => 'ลงนามดิจิทัล / ลายเซ็นอิเล็กทรอนิกส์',
                'saraban.circular.broadcast' => 'ออกหนังสือเวียนถึงทุกแผนก',
                'saraban.setting.manage' => 'ตั้งค่าระบบสารบรรณและสายงานอนุมัติ',
            ],
            'system' => [
                'system.user.view' => 'ดูรายการข้อมูลผู้ใช้งาน',
                'system.user.manage' => 'เพิ่ม/แก้ไข/ระงับผู้ใช้งานระบบ',
                'system.role.manage' => 'จัดการบทบาทและสิทธิ์การเข้าถึง (Roles & Permissions)',
                'system.menu.manage' => 'จัดการเมนูตามสิทธิ์ (Dynamic Menus)',
                'system.setting.manage' => 'ตั้งค่าระบบและข้อมูลสถานศึกษา',
            ],
        ];

        // 2. Legacy Permissions (Backward Compatibility Layer)
        $legacyPermissions = [
            'view_users',
            'edit_users',
            'delete_users',
            'manage_settings',
            'manage_departments',
            'manage_leave_types',
            'manage_workflow_steps',
            'approve_leave',
            'approve_leaves',
            'view_all_attendances',  // legacy — ยังคงไว้เพื่อ backward compatibility
            'export_reports',
        ];

        // Create all permissions
        $createdPermissions = [];
        foreach ($permissionGroups as $module => $perms) {
            foreach ($perms as $code => $description) {
                $p = Permission::firstOrCreate(['name' => $code, 'guard_name' => 'web']);
                $createdPermissions[$code] = $p;
            }
        }

        foreach ($legacyPermissions as $legacyCode) {
            $p = Permission::firstOrCreate(['name' => $legacyCode, 'guard_name' => 'web']);
            $createdPermissions[$legacyCode] = $p;
        }

        // 3. Define EXACT 7 Standard Roles Only
        $rolesConfig = [
            // 1. ผู้ดูแลระบบสูงสุด
            'ผู้ดูแลระบบสูงสุด' => [
                'description' => 'ผู้ดูแลระบบสูงสุด (สิทธิ์การเข้าถึงเต็มรูปแบบทุกระบบ)',
                'permissions' => array_keys($createdPermissions),
            ],

            // 2. ผู้อำนวยการ
            'ผู้อำนวยการ' => [
                'description' => 'ผู้อำนวยการวิทยาลัย',
                'permissions' => [
                    'leave.request.create', 'leave.request.view_my', 'leave.request.cancel',
                    'leave.approval.dept', 'leave.approval.final', 'leave.report.view',
                    'attendance.checkin', 'attendance.view_self', 'attendance.view_all', 'attendance.export', 'attendance.ot_summary',
                    'saraban.document.view', 'saraban.document.create', 'saraban.document.approve', 'saraban.document.sign', 'saraban.circular.broadcast',
                    'approve_leave', 'approve_leaves', 'view_all_attendances', 'export_reports',
                ],
            ],

            // 3. รองผู้อำนวยการ
            'รองผู้อำนวยการ' => [
                'description' => 'รองผู้อำนวยการวิทยาลัย',
                'permissions' => [
                    'leave.request.create', 'leave.request.view_my', 'leave.request.cancel',
                    'leave.approval.dept', 'leave.report.view',
                    'attendance.checkin', 'attendance.view_self', 'attendance.view_all', 'attendance.export', 'attendance.ot_summary',
                    'saraban.document.view', 'saraban.document.create', 'saraban.document.approve', 'saraban.document.sign',
                    'approve_leave', 'approve_leaves', 'view_all_attendances', 'export_reports',
                ],
            ],

            // 4. หัวหน้าแผนกวิชา / หัวหน้าฝ่าย
            'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย' => [
                'description' => 'หัวหน้าแผนกวิชา / หัวหน้างาน / หัวหน้าฝ่าย',
                'permissions' => [
                    'leave.request.create', 'leave.request.view_my', 'leave.request.cancel',
                    'leave.approval.dept', 'leave.report.view',
                    'attendance.checkin', 'attendance.view_self',
                    'saraban.document.view', 'saraban.document.create', 'saraban.document.approve', 'saraban.document.sign',
                    'approve_leave', 'approve_leaves',
                ],
            ],

            // 5. เจ้าหน้าที่บุคลากร (HR)
            'เจ้าหน้าที่บุคลากร (HR)' => [
                'description' => 'เจ้าหน้าที่งานบุคลากร (HR)',
                'permissions' => [
                    'leave.request.create', 'leave.request.view_my', 'leave.request.cancel', 'leave.management.manage', 'leave.report.view',
                    'attendance.checkin', 'attendance.view_self', 'attendance.view_all', 'attendance.export', 'attendance.ot', 'attendance.ot_summary',
                    'system.user.view', 'system.user.manage',
                    'view_users', 'edit_users', 'view_all_attendances', 'export_reports',
                ],
            ],

            // 6. เจ้าหน้าที่สารบรรณกลาง
            'เจ้าหน้าที่สารบรรณกลาง' => [
                'description' => 'เจ้าหน้าที่งานสารบรรณกลางวิทยาลัย',
                'permissions' => [
                    'leave.request.create', 'leave.request.view_my', 'leave.request.cancel',
                    'attendance.checkin', 'attendance.view_self', 'attendance.record.create', 'attendance.record.view_my',
                    'saraban.document.view', 'saraban.document.create', 'saraban.document.edit', 'saraban.document.delete',
                    'saraban.document.approve', 'saraban.document.sign', 'saraban.circular.broadcast', 'saraban.setting.manage',
                ],
            ],

            // 7. บุคลากร / ครู / เจ้าหน้าที่
            'บุคลากร / ครู / เจ้าหน้าที่' => [
                'description' => 'ครูและบุคลากรทางการศึกษาทั่วไป',
                'permissions' => [
                    'leave.request.create', 'leave.request.view_my', 'leave.request.cancel',
                    'attendance.checkin', 'attendance.view_self', 'attendance.record.create', 'attendance.record.view_my',
                    'saraban.document.view', 'saraban.document.create',
                ],
            ],
        ];

        foreach ($rolesConfig as $roleName => $config) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            if (method_exists($role, 'setAttribute') && Schema::hasColumn('roles', 'description')) {
                $role->description = $config['description'];
                $role->save();
            }
            $role->syncPermissions($config['permissions']);
        }
    }
}
