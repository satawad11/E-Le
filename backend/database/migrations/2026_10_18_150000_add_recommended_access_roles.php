<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ACCESS-GOV-01 · B5 — บทบาทที่ยังขาดตามแผน (docs/ACCESS-GOVERNANCE-2569.md หัวข้อ 4.2)
 *
 * บทบาทเดิมครอบคลุมแล้ว: Employee (บุคลากร), Director/Deputy Director (ผู้บริหาร), HR Head (หัวหน้างานบุคคล),
 * Document Officer (สารบรรณ), Super Admin (ผู้ดูแลระบบ — เจ้าของระบบตอบ Q5 ให้คงเดิม)
 * เพิ่มเฉพาะสามบทบาทที่ยังไม่มี ข้อมูลเท่านั้น ไม่แตะผู้ใช้หรือบทบาทเดิม:
 *
 *   HR Officer         เจ้าหน้าที่งานบริหารและพัฒนาทรัพยากรบุคคล (ข้อ 15) — ดูข้อมูลบุคลากร/ลงเวลา/รายงานการลา
 *                      จัดทำคำสั่ง OT และทะเบียนตำแหน่ง ไม่มี leave.management.manage (ไม่เป็นผู้ตรวจสอบในสายลา
 *                      และไม่จัดการวันลา) และไม่มีสิทธิ์ให้บทบาท/สิทธิ์
 *   Auditor            ผู้ตรวจสอบ งานติดตามและประเมินผล (ข้อ 26) — รายงานอ่านอย่างเดียว
 *   Overtime Operator  ผู้จัดทำคำสั่งปฏิบัติงานล่วงเวลาในหน่วยงานของตน
 *
 * คำอธิบายภาษาไทยของบทบาทเดิมเติมให้เฉพาะที่ยังว่าง ไม่ทับค่าที่ผู้ดูแลตั้งไว้
 */
return new class extends Migration
{
    private const BASE = ['attendance.checkin', 'attendance.view_self'];

    private const ROLES = [
        'HR Officer' => [
            'description' => 'เจ้าหน้าที่งานบริหารและพัฒนาทรัพยากรบุคคล',
            'permissions' => ['view_users', 'attendance.view_all', 'leave.report.view', 'system.position.manage', 'attendance.ot_summary', 'overtime.operation.execute'],
        ],
        'Auditor' => [
            'description' => 'ผู้ตรวจสอบ (งานติดตามและประเมินผล) — ดูรายงานอย่างเดียว',
            'permissions' => ['leave.report.view', 'attendance.view_all', 'attendance.ot_summary', 'saraban.document.report'],
        ],
        'Overtime Operator' => [
            'description' => 'ผู้จัดทำคำสั่งปฏิบัติงานล่วงเวลา',
            'permissions' => ['overtime.operation.execute'],
        ],
    ];

    private const EXISTING_DESCRIPTIONS = [
        'Employee' => 'บุคลากร (ครู เจ้าหน้าที่ ลูกจ้าง)',
        'Director' => 'ผู้อำนวยการสถานศึกษา',
        'Deputy Director' => 'รองผู้อำนวยการสถานศึกษา',
        'HR Head' => 'หัวหน้างานบริหารและพัฒนาทรัพยากรบุคคล',
        'Document Officer' => 'เจ้าหน้าที่งานบริหารงานทั่วไป (สารบรรณ)',
        'Super Admin' => 'ผู้ดูแลระบบสูงสุด',
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $hasDescription = Schema::hasColumn('roles', 'description');

        foreach (self::ROLES as $name => $config) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
            if ($hasDescription && blank($role->description)) {
                $role->forceFill(['description' => $config['description']])->save();
            }
            foreach (array_merge(self::BASE, $config['permissions']) as $permission) {
                $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]));
            }
        }

        if ($hasDescription) {
            foreach (self::EXISTING_DESCRIPTIONS as $name => $description) {
                Role::where('name', $name)->where('guard_name', $guard)
                    ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
                    ->update(['description' => $description]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (array_keys(self::ROLES) as $name) {
            $role = Role::where('name', $name)->where('guard_name', $guard)->first();
            // ถอนได้เฉพาะเมื่อยังไม่มีใครถือบทบาทนี้ — ไม่ทำให้ผู้ใช้หลุดบทบาทเงียบ ๆ
            if ($role !== null && $role->users()->count() === 0) {
                $role->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
