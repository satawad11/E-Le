<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * R02-9 — ระเบียบการลาเป็นข้อมูลตั้งต้นที่มีรุ่น (ADR-R-005)
 *
 * ข้อมูลใน `data/leave_types.json` และ `data/leave_policies.json` คือรูปสุดท้ายที่ migration ชุดเดิม
 * (seed_default_thai_government_leave_policies, seed_thai_government_leave_types, backfill_*,
 * set_sick_leave_paid_and_total_caps, align_leave_policies_with_regulation ฯลฯ ใน migrations-legacy/)
 * เคยใส่ไว้ ส่งออกจากฐานข้อมูลทดสอบที่รัน migration ชุดเดิมครบทุกไฟล์
 *
 * ระบบเดิมแบ่งบุคลากร 4 ประเภท ระบบใหม่มี 8 ประเภท (CORE-02) จึงใช้ระเบียบของประเภทเดิมกับประเภทใหม่
 * ตาม LEGACY_TYPE_MAP — เป็นค่าเสนอที่รอเจ้าของระบบยืนยัน (TASKS.md "รอตัดสินใจ" Q1, Q8)
 *
 * idempotent: ประเภทการลาค้นตามชื่อ · นโยบายค้นตาม (ประเภทบุคลากร, ประเภทการลา, effective_from)
 */
class LeaveRegulationSeeder extends Seeder
{
    /** ประเภทบุคลากรของระบบเดิม → ประเภทใหม่ที่ใช้ระเบียบชุดเดียวกัน */
    public const LEGACY_TYPE_MAP = [
        'civil_servant' => ['civil_teacher', 'assistant_teacher', 'education_personnel_38c2'],
        'permanent_employee' => ['permanent_employee'],
        'government_employee' => ['government_employee'],
        'temporary_employee' => ['contract_teacher', 'temporary_employee', 'project_staff'],
    ];

    public function run(): void
    {
        $now = now();

        foreach ($this->fixture('leave_types.json') as $type) {
            DB::table('leave_types')->updateOrInsert(
                ['name' => $type['name']],
                $this->encode($type) + ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $leaveTypes = DB::table('leave_types')->pluck('id', 'name');
        $personnelTypes = DB::table('personnel_types')->pluck('id', 'code');

        foreach ($this->fixture('leave_policies.json') as $policy) {
            $legacyType = $policy['legacy_personnel_type'];
            $leaveTypeId = $leaveTypes[$policy['leave_type']] ?? throw new RuntimeException("ไม่พบประเภทการลา {$policy['leave_type']}");
            $values = $this->encode(array_diff_key($policy, array_flip(['legacy_personnel_type', 'leave_type'])));

            foreach (self::LEGACY_TYPE_MAP[$legacyType] ?? throw new RuntimeException("ไม่รู้จักประเภทบุคลากรเดิม {$legacyType}") as $code) {
                $personnelTypeId = $personnelTypes[$code] ?? throw new RuntimeException("ยังไม่มีประเภทบุคลากร {$code} — รัน PersonnelTypeSeeder ก่อน");

                DB::table('leave_policies')->updateOrInsert(
                    ['personnel_type_id' => $personnelTypeId, 'leave_type_id' => $leaveTypeId, 'effective_from' => $values['effective_from']],
                    $values + ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fixture(string $file): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/data/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encode(array $row): array
    {
        return array_map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value, $row);
    }
}
