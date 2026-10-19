<?php

namespace Database\Seeders;

use App\Models\PersonnelType;
use Illuminate\Database\Seeder;

/**
 * R02-9 — ประเภทบุคลากร 8 แบบตาม CORE-02 §personnel_types
 *
 * `has_license` / `has_contract_end` เป็นค่าเสนอ (ADR-R-005) รอเจ้าของระบบยืนยันใน TASKS.md "รอตัดสินใจ" Q8
 * idempotent: อัปเดตตาม `code`
 */
class PersonnelTypeSeeder extends Seeder
{
    public const TYPES = [
        ['code' => 'civil_teacher', 'name' => 'ข้าราชการครู', 'has_license' => true, 'has_contract_end' => false],
        ['code' => 'assistant_teacher', 'name' => 'ครูผู้ช่วย', 'has_license' => true, 'has_contract_end' => false],
        ['code' => 'government_employee', 'name' => 'พนักงานราชการ', 'has_license' => false, 'has_contract_end' => true],
        ['code' => 'contract_teacher', 'name' => 'ครูอัตราจ้าง', 'has_license' => false, 'has_contract_end' => true],
        ['code' => 'permanent_employee', 'name' => 'ลูกจ้างประจำ', 'has_license' => false, 'has_contract_end' => false],
        ['code' => 'temporary_employee', 'name' => 'ลูกจ้างชั่วคราว', 'has_license' => false, 'has_contract_end' => true],
        ['code' => 'project_staff', 'name' => 'เจ้าหน้าที่ตามสัญญา/โครงการ', 'has_license' => false, 'has_contract_end' => true],
        ['code' => 'education_personnel_38c2', 'name' => 'บุคลากรทางการศึกษาอื่น ม.38 ค.(2)', 'has_license' => false, 'has_contract_end' => false],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            PersonnelType::updateOrCreate(['code' => $type['code']], $type + ['is_active' => true]);
        }
    }
}
