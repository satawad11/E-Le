<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $types = [
            ['code' => 'civil_servant', 'name' => 'ข้าราชการ'],
            ['code' => 'permanent_employee', 'name' => 'ลูกจ้างประจำ'],
            ['code' => 'government_employee', 'name' => 'พนักงานราชการ'],
            ['code' => 'temporary_employee', 'name' => 'ลูกจ้างชั่วคราว'],
        ];
        foreach ($types as $type) {
            DB::table('personnel_types')->updateOrInsert(['code' => $type['code']], $type + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $defaultId = DB::table('personnel_types')->where('code', 'civil_servant')->value('id');
        DB::table('users')->whereNull('personnel_type_id')->update(['personnel_type_id' => $defaultId]);

        $aliases = [
            'sick' => ['ป่วย'], 'personal' => ['กิจ'], 'vacation' => ['พักผ่อน'], 'maternity' => ['คลอด'],
            'paternity' => ['ช่วยเหลือภริยา'], 'ordination' => ['อุปสมบท'], 'hajj' => ['ฮัจย์'],
            'military' => ['ทหาร', 'ตรวจเลือก', 'เตรียมพล'], 'study' => ['ศึกษา', 'ฝึกอบรม'], 'spouse' => ['ติดตามคู่สมรส'],
        ];
        $defaults = [
            'civil_servant' => ['sick' => [60, true, 3], 'personal' => [45, false, null], 'vacation' => [10, false, null], 'maternity' => [98, true, null], 'paternity' => [15, false, null]],
            'government_employee' => ['sick' => [30, true, 3], 'personal' => [10, false, null], 'vacation' => [10, false, null], 'maternity' => [90, true, null]],
            'permanent_employee' => ['sick' => [60, true, 3], 'personal' => [45, false, null], 'vacation' => [10, false, null], 'maternity' => [98, true, null]],
            'temporary_employee' => ['sick' => [15, true, 3], 'personal' => [5, false, null], 'vacation' => [6, false, null], 'maternity' => [45, true, null]],
        ];
        foreach (DB::table('personnel_types')->get() as $personnel) {
            foreach (DB::table('leave_types')->get() as $leaveType) {
                $key = null;
                foreach ($aliases as $candidate => $needles) {
                    foreach ($needles as $needle) {
                        if (str_contains($leaveType->name, $needle)) {
                            $key = $candidate;
                            break 2;
                        }
                    }
                }
                $rule = $defaults[$personnel->code][$key] ?? [null, false, null];
                DB::table('leave_policies')->updateOrInsert(
                    ['personnel_type_id' => $personnel->id, 'leave_type_id' => $leaveType->id, 'effective_from' => '2026-10-01'],
                    ['annual_quota' => $rule[0], 'attachment_required_after_days' => $rule[2], 'required_evidence_type' => $rule[1] ? 'medical_certificate' : null, 'allow_past_dates' => $key === 'sick', 'exclude_holidays' => ! in_array($key, ['maternity', 'ordination', 'hajj', 'military', 'study', 'spouse'], true), 'allow_half_day' => in_array($key, ['sick', 'personal', 'vacation'], true), 'is_carry_forward' => $key === 'vacation', 'max_carry_forward' => $personnel->code === 'government_employee' ? 5 : 20, 'max_accumulated' => $key === 'vacation' ? ($personnel->code === 'government_employee' ? 15 : 30) : null, 'period_basis' => 'fiscal_year', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void {}
};
