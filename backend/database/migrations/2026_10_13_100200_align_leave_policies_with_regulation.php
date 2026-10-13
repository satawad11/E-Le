<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ปรับค่าเริ่มต้นของนโยบายการลาให้ตรงระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555
 * (และระเบียบกระทรวงการคลังว่าด้วยการลาของลูกจ้างประจำ ซึ่งใช้หลักเดียวกัน)
 *
 * แตะเฉพาะแถวที่ยังเป็นค่าเริ่มต้นจาก seed เดิม — ค่าที่ผู้ดูแลแก้ไว้แล้วไม่ถูกเขียนทับ
 *
 * 1. ลาพักผ่อน — ข้อ 28: วันสะสมรวมกับปีปัจจุบันไม่เกิน 20 วันทำการ
 *    ถ้ารับราชการติดต่อกันมาแล้วไม่น้อยกว่า 10 ปี ไม่เกิน 30 วันทำการ
 *    (เดิมตั้ง 30 ให้ทุกคน)
 * 2. ลาพักผ่อน — ข้อ 27: รับราชการไม่ถึง 6 เดือนไม่มีสิทธิลาพักผ่อน (เดิม 0 วัน)
 * 3. ลาไปช่วยเหลือภริยาที่คลอดบุตร — สิทธิของข้าราชการชาย (เดิมไม่จำกัด)
 */
return new class extends Migration
{
    public function up(): void
    {
        $vacation = DB::table('leave_types')->where('name', 'ลาพักผ่อน')->value('id');
        $paternity = DB::table('leave_types')->where('name', 'ลาไปช่วยเหลือภริยาที่คลอดบุตร')->value('id');
        $longServiceTypes = DB::table('personnel_types')->whereIn('code', ['civil_servant', 'permanent_employee'])->pluck('id');
        $sixMonthTypes = DB::table('personnel_types')->whereIn('code', ['civil_servant', 'permanent_employee', 'government_employee'])->pluck('id');

        if ($vacation) {
            DB::table('leave_policies')
                ->where('leave_type_id', $vacation)
                ->whereIn('personnel_type_id', $longServiceTypes)
                ->where('max_accumulated', 30)
                ->whereNull('eligibility_rules')
                ->update([
                    'max_accumulated' => 20,
                    'eligibility_rules' => json_encode(['long_service_years' => 10, 'max_accumulated_long_service' => 30]),
                    'updated_at' => now(),
                ]);

            DB::table('leave_policies')
                ->where('leave_type_id', $vacation)
                ->whereIn('personnel_type_id', $sixMonthTypes)
                ->where('minimum_service_days', 0)
                ->update(['minimum_service_days' => 180, 'updated_at' => now()]);
        }

        if ($paternity) {
            DB::table('leave_policies')
                ->where('leave_type_id', $paternity)
                ->whereNull('eligibility_rules')
                ->update(['eligibility_rules' => json_encode(['gender' => 'male']), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // ข้อมูลนโยบาย — ไม่ย้อนกลับอัตโนมัติ เพื่อไม่ทับค่าที่ผู้ดูแลแก้หลัง migrate
    }
};
