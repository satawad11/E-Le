<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * กำหนดการลาที่ต้องส่งต่อผู้มีอำนาจเหนือสถานศึกษาหลังผู้อำนวยการอนุมัติ (REDESIGN-2569 · D-01 เจ้าของระบบ 18/10/2569)
 *
 * ตั้งเฉพาะที่คู่มือการลาระบุชัดว่าผู้มีอำนาจอยู่เหนือหัวหน้าหน่วยงาน:
 * - ลาติดตามคู่สมรส (หัวข้อ 10: เสนอจนถึงปลัดกระทรวงหรือหัวหน้าส่วนราชการ)
 * - ลาไปปฏิบัติงานในองค์การระหว่างประเทศ (หัวข้อ 9: ตามพระราชกฤษฎีกาฯ)
 * - ลาป่วยที่รวมในปีเกิน 60 วัน (หัวข้อ 1.3: ส่งเรื่องเพื่อขออนุญาตต่อผู้มีอำนาจระดับกระทรวง) — ข้าราชการและลูกจ้างประจำ
 * ใบลาของผู้อำนวยการเองส่งต่อเสมอ (ตรวจในโค้ด ไม่ใช่นโยบาย)
 * ประเภทอื่น (ลาศึกษาต่อ ลาฟื้นฟูสมรรถภาพ ฯลฯ) เปิดได้ที่หน้านโยบายการลาเมื่อได้คำสั่งมอบอำนาจ
 *
 * แก้แบบมีรุ่น: นโยบายที่มีใบลาอ้างถึงแล้วจะปิดรุ่นเดิมเมื่อวานและสร้างรุ่นใหม่วันนี้ ที่ยังไม่มีใบลาแก้ในแถวเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->apply('ลาติดตามคู่สมรส', null, ['requires_external_approval' => true]);
        $this->apply('ลาไปปฏิบัติงานในองค์การระหว่างประเทศ', null, ['requires_external_approval' => true]);
        $this->apply('ลาป่วย', ['civil_servant', 'permanent_employee'], ['external_approval_after_days' => 60]);
    }

    /** ถอดเฉพาะคีย์ที่ migration นี้เพิ่ม ในรุ่นที่ยังเปิดอยู่ (รุ่นที่ปิดไปแล้วเป็นประวัติ ไม่แตะ) */
    public function down(): void
    {
        foreach (['requires_external_approval', 'external_approval_after_days'] as $key) {
            foreach (DB::table('leave_policies')->whereNull('effective_to')->whereNotNull('eligibility_rules')->get() as $policy) {
                $rules = json_decode($policy->eligibility_rules, true) ?: [];
                if (array_key_exists($key, $rules)) {
                    unset($rules[$key]);
                    DB::table('leave_policies')->where('id', $policy->id)->update(['eligibility_rules' => $rules === [] ? null : json_encode($rules), 'updated_at' => now()]);
                }
            }
        }
    }

    private function apply(string $leaveType, ?array $personnelCodes, array $add): void
    {
        $query = DB::table('leave_policies')
            ->whereIn('leave_type_id', DB::table('leave_types')->where('name', $leaveType)->pluck('id'))
            ->where('is_active', true)
            ->whereNull('effective_to');
        if ($personnelCodes !== null) {
            $query->whereIn('personnel_type_id', DB::table('personnel_types')->whereIn('code', $personnelCodes)->pluck('id'));
        }

        foreach ($query->get() as $policy) {
            $rules = json_decode((string) $policy->eligibility_rules, true) ?: [];
            if (array_intersect_key($add, $rules) !== []) {
                continue; // ผู้ดูแลตั้งค่าไว้แล้ว
            }
            $rules = array_merge($rules, $add);

            $inUse = DB::table('leaves')->where('leave_policy_id', $policy->id)->exists();
            if (! $inUse || $policy->effective_from >= today()->toDateString()) {
                DB::table('leave_policies')->where('id', $policy->id)->update(['eligibility_rules' => json_encode($rules), 'updated_at' => now()]);

                continue;
            }

            $next = (array) $policy;
            unset($next['id']);
            $next['effective_from'] = today()->toDateString();
            $next['eligibility_rules'] = json_encode($rules);
            $next['created_at'] = now();
            $next['updated_at'] = now();
            DB::table('leave_policies')->where('id', $policy->id)->update(['effective_to' => today()->subDay()->toDateString(), 'updated_at' => now()]);
            DB::table('leave_policies')->insert($next);
        }
    }
};
