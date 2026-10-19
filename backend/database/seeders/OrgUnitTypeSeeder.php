<?php

namespace Database\Seeders;

use App\Models\OrgUnitType;
use Illuminate\Database\Seeder;

/**
 * R02-9 — ประเภทหน่วยงาน 6 แบบ (CORE-01, ADR-R-009)
 *
 * ระดับ L1–L4 เป็นข้อมูล ชื่อประเภทหน่วย L4 ใช้ค่าเริ่มต้นของ ADR-R-009 (กลุ่มงาน / สาขางาน)
 * จนกว่าเจ้าของระบบตอบคำถาม Q4 — ผู้ดูแลแก้ชื่อได้โดยไม่กระทบตรรกะเพราะตรรกะอ่าน `code` และ `level`
 *
 * idempotent: อัปเดตตาม `code`
 */
class OrgUnitTypeSeeder extends Seeder
{
    public const TYPES = [
        ['code' => 'institution', 'name' => 'สถานศึกษา', 'level' => 1, 'head_title' => 'ผู้อำนวยการ'],
        ['code' => 'division', 'name' => 'ฝ่าย', 'level' => 2, 'head_title' => 'รองผู้อำนวยการ'],
        ['code' => 'section', 'name' => 'งาน', 'level' => 3, 'head_title' => 'หัวหน้างาน'],
        ['code' => 'department', 'name' => 'แผนกวิชา', 'level' => 3, 'head_title' => 'หัวหน้าแผนกวิชา'],
        ['code' => 'subsection', 'name' => 'กลุ่มงาน', 'level' => 4, 'head_title' => 'หัวหน้ากลุ่มงาน'],
        ['code' => 'program', 'name' => 'สาขางาน', 'level' => 4, 'head_title' => 'หัวหน้าสาขางาน'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            OrgUnitType::updateOrCreate(['code' => $type['code']], $type + ['is_active' => true]);
        }
    }
}
