<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * migration 2026_08_08_100100 สร้างนโยบายสิทธิการลาไว้ก่อนที่ประเภทการลาชุดจริงจะถูก seed
 * (2026_08_09_050000 อยู่ batch ถัดมา) ผลคือ leave_policies แทบว่าง และระบบไปใช้ค่า max_days
 * เป็นสิทธิ์แทน ทำให้คนละคนได้สิทธิ์ลาชนิดเดียวกันไม่เท่ากัน
 *
 * migration นี้เติมนโยบายที่ยังขาดให้ครบทุกคู่ (ประเภทบุคลากร × ประเภทการลา)
 * โดยไม่แตะแถวที่มีอยู่แล้ว ยกเว้นแถวที่ระบบสร้างเองอัตโนมัติจาก max_days ซึ่งจะถูกปิดใช้งาน
 */
return new class extends Migration
{
    private const EFFECTIVE_FROM = '2025-10-01';

    public function up(): void
    {
        // ลำดับสำคัญ: ต้องตรวจ paternity ก่อน maternity ไม่งั้น "ลาไปช่วยเหลือภริยาที่คลอดบุตร"
        // จะไปเข้าเงื่อนไข "คลอด" ของ maternity (ข้อผิดพลาดเดิมใน seed ชุดแรก)
        $aliases = [
            'paternity' => ['ช่วยเหลือภริยา'],
            'maternity' => ['คลอด'],
            'sick' => ['ป่วย'],
            'personal' => ['กิจ'],
            'vacation' => ['พักผ่อน'],
            'ordination' => ['อุปสมบท'],
            'hajj' => ['ฮัจ'], // ชื่อจริงสะกด "ฮัจญ์" seed ชุดแรกใช้ "ฮัจย์" จึงไม่เคยแมตช์
            'military' => ['ตรวจเลือก', 'เตรียมพล', 'ทหาร'],
            'study' => ['ศึกษา', 'ฝึกอบรม', 'ดูงาน'],
            'spouse' => ['ติดตามคู่สมรส'],
        ];

        // [โควตาต่อปี (null = ไม่จำกัดโควตา), ต้องมีใบรับรองแพทย์, ต้องแนบหลักฐานเมื่อลาตั้งแต่กี่วัน]
        $defaults = [
            'civil_servant' => ['sick' => [60, true, 3], 'personal' => [45, false, null], 'vacation' => [10, false, null], 'maternity' => [98, true, null], 'paternity' => [15, false, null], 'ordination' => [120, false, null], 'hajj' => [120, false, null]],
            'permanent_employee' => ['sick' => [60, true, 3], 'personal' => [45, false, null], 'vacation' => [10, false, null], 'maternity' => [98, true, null], 'paternity' => [15, false, null], 'ordination' => [120, false, null], 'hajj' => [120, false, null]],
            'government_employee' => ['sick' => [30, true, 3], 'personal' => [10, false, null], 'vacation' => [10, false, null], 'maternity' => [90, true, null], 'paternity' => [15, false, null]],
            'temporary_employee' => ['sick' => [15, true, 3], 'personal' => [5, false, null], 'vacation' => [6, false, null], 'maternity' => [45, true, null]],
        ];

        $now = now();

        foreach (DB::table('personnel_types')->get() as $personnel) {
            foreach (DB::table('leave_types')->get() as $leaveType) {
                $key = null;
                foreach ($aliases as $candidate => $needles) {
                    foreach ($needles as $needle) {
                        if (str_contains($leaveType->name, $needle)) { $key = $candidate; break 2; }
                    }
                }

                // ปิดใช้งานนโยบายที่ระบบสร้างเองอัตโนมัติ (คัดลอกโควตามาจาก max_days)
                // ระบุได้จาก effective_from ที่ไม่ใช่วันเริ่มปีงบประมาณ เพราะ fallback ใช้ต้นปีปฏิทิน
                DB::table('leave_policies')
                    ->where('personnel_type_id', $personnel->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('effective_from', '!=', self::EFFECTIVE_FROM)
                    ->update(['is_active' => 0, 'updated_at' => $now]);

                $exists = DB::table('leave_policies')
                    ->where('personnel_type_id', $personnel->id)
                    ->where('leave_type_id', $leaveType->id)
                    ->where('effective_from', self::EFFECTIVE_FROM)
                    ->exists();
                if ($exists) continue;

                $rule = $defaults[$personnel->code][$key] ?? [null, false, null];

                DB::table('leave_policies')->insert([
                    'personnel_type_id' => $personnel->id,
                    'leave_type_id' => $leaveType->id,
                    'effective_from' => self::EFFECTIVE_FROM,
                    'annual_quota' => $rule[0],
                    'attachment_required_after_days' => $rule[2],
                    'required_evidence_type' => $rule[1] ? 'medical_certificate' : null,
                    'allow_past_dates' => $key === 'sick',
                    'exclude_holidays' => !in_array($key, ['maternity', 'ordination', 'hajj', 'military', 'study', 'spouse'], true),
                    'allow_half_day' => in_array($key, ['sick', 'personal', 'vacation'], true),
                    'is_carry_forward' => $key === 'vacation',
                    'max_carry_forward' => $personnel->code === 'government_employee' ? 5 : 20,
                    'max_accumulated' => $key === 'vacation' ? ($personnel->code === 'government_employee' ? 15 : 30) : null,
                    'period_basis' => 'fiscal_year',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('leave_policies')->where('effective_from', self::EFFECTIVE_FROM)->delete();
    }
};
