<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ตั้งเงื่อนไขเฉพาะของการลาตามระเบียบลงใน leave_policies.eligibility_rules
 * ซึ่งเดิมเป็นช่องว่างที่ไม่มีโค้ดไหนอ่าน
 *
 *  - ลาอุปสมบท / ลาไปประกอบพิธีฮัจญ์ : ใช้สิทธิ์ได้ครั้งเดียวตลอดอายุราชการ
 *  - ลาคลอดบุตร                      : เฉพาะเพศหญิง
 *
 * แตะเฉพาะนโยบายที่ยังไม่เคยตั้งกฎไว้ เพื่อไม่ทับค่าที่งานบุคลากรกำหนดเอง
 */
return new class extends Migration
{
    public function up(): void
    {
        $rulesByKeyword = [
            'อุปสมบท'   => ['once_in_career' => true],
            'ฮัจ'       => ['once_in_career' => true],
            'คลอดบุตร'  => ['gender' => 'female'],
        ];

        foreach (DB::table('leave_types')->get() as $type) {
            // "ลาไปช่วยเหลือภริยาที่คลอดบุตร" เป็นสิทธิ์ของสามี ไม่ใช่ผู้คลอด จึงไม่จำกัดเพศหญิง
            if (str_contains($type->name, 'ช่วยเหลือภริยา')) continue;

            foreach ($rulesByKeyword as $keyword => $rules) {
                if (!str_contains($type->name, $keyword)) continue;

                DB::table('leave_policies')
                    ->where('leave_type_id', $type->id)
                    ->whereNull('eligibility_rules')
                    ->update(['eligibility_rules' => json_encode($rules), 'updated_at' => now()]);
                break;
            }
        }
    }

    public function down(): void
    {
        DB::table('leave_policies')->update(['eligibility_rules' => null]);
    }
};
