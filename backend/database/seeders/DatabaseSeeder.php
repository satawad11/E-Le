<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder ระดับติดตั้ง (REBUILD-2569 baseline · R02-9)
 *
 * ทุกตัว idempotent รันซ้ำได้โดยไม่สร้างข้อมูลซ้ำและไม่ลบของเดิม
 *
 * สิ่งที่ **ไม่มี** ใน baseline โดยตั้งใจ:
 *   - บัญชีผู้ใช้ทดสอบ บทบาท สิทธิ์ เมนู — สิทธิ์และเมนูมาจาก manifest ของโมดูล (`modules:sync`, R-04)
 *     บัญชีเกิดจากข้อมูลบุคลากร (JML, R-05) ไม่มีรหัสผ่านตั้งต้นในระบบ
 *   - หน่วยงานระดับงาน/แผนกวิชา บุคลากร ตำแหน่ง — นำเข้าใน R-03 จากไฟล์ที่งานบุคคลยืนยัน
 *   - สายอนุมัติ (approval_steps / workflow definition ของการลา) — R-04/R-08 สร้างจาก manifest (ADR-R-006)
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // CORE-01 / CORE-02
            OrgUnitTypeSeeder::class,
            OrganizationBaselineSeeder::class,
            PersonnelTypeSeeder::class,
            FieldClassificationSeeder::class,

            // ระเบียบราชการเป็นข้อมูลตั้งต้นที่มีรุ่น (ADR-R-005)
            FiscalYearSeeder::class,
            ThaiGovernmentHoliday2569Seeder::class,
            ThaiGovernmentHoliday2570Seeder::class,
            LeaveRegulationSeeder::class,
            NewLeaveTypesSeeder::class,
            LeaveFormExtraFieldSeeder::class,
            CancelSettingSeeder::class,
            WorkScheduleSeeder::class,

            // แม่แบบเอกสาร สารบรรณ และการแจ้งเตือน
            DocumentTemplateSeeder::class,
            SarabanDocumentTypeSeeder::class,
            SarabanSettingSeeder::class,
            NotificationCatalogSeeder::class,
            DocumentEngineSeeder::class,
        ]);
    }
}
