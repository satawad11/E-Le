<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ลากิจส่วนตัวเพื่อเลี้ยงดูบุตร (REDESIGN-2569 เฟส 2.3 · D-02 เจ้าของระบบ 18/10/2569)
 *
 * คู่มือการลาหัวข้อ 4.2: ข้าราชการที่ลาคลอดบุตรแล้ว มีสิทธิลากิจส่วนตัวเพื่อเลี้ยงดูบุตรต่อเนื่องจากการลาคลอดบุตร
 * โดยไม่ได้รับเงินเดือน — เจ้าของระบบกำหนดว่าลาได้เฉพาะต่อจากวันสุดท้ายของลาคลอดเท่านั้น
 *
 * - แยกเป็นประเภทการลาของตัวเอง เพื่อไม่หักโควตาลากิจปกติ และแยกในรายงาน/ทะเบียนคุมได้
 * - ใช้แบบใบลาเดียวกับลากิจส่วนตัว (แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว) และเลขที่หนังสือ ลก.
 * - **โควตาเริ่มต้น 0 วัน** — เจ้าของระบบจะกรอกจำนวนวันเองที่หน้านโยบายการลา ระหว่างนี้จึงยื่นไม่ได้
 *   (ไม่ปล่อยว่าง เพราะค่าว่าง = ไม่จำกัด และไม่ปล่อยให้ระบบสร้างนโยบายให้เอง)
 * - ไม่ได้รับเงินเดือน (max_days_with_pay = 0) · นับเฉพาะวันทำการ
 * - เงื่อนไข: ต้องต่อจากลาคลอดบุตรที่อนุญาตแล้ว โดยไม่มีวันทำงานคั่น (requires_predecessor_leave_type_id)
 */
return new class extends Migration
{
    private const NAME = 'ลากิจส่วนตัวเพื่อเลี้ยงดูบุตร';

    private const MATERNITY = 'ลาคลอดบุตร';

    public function up(): void
    {
        $maternityId = DB::table('leave_types')->where('name', self::MATERNITY)->value('id');
        if (! $maternityId || DB::table('leave_types')->where('name', self::NAME)->exists()) {
            return;
        }

        $typeId = DB::table('leave_types')->insertGetId([
            'name' => self::NAME,
            'description' => 'ลากิจส่วนตัวต่อเนื่องจากการลาคลอดบุตร เพื่อเลี้ยงดูบุตร โดยไม่ได้รับเงินเดือน ต้องเริ่มหลังวันสุดท้ายของการลาคลอดบุตร และระหว่างลาผู้บังคับบัญชาเรียกตัวกลับไม่ได้',
            'max_days' => 0,
            'requires_doc' => 0,
            'code_prefix' => 'ลก.',
            'document_template_code' => 'leave_sick_personal',
            'counts_toward_promotion' => 1,
            'is_prorated' => 0,
            'is_carry_forward' => 0,
            'exclude_holidays' => 1,
            'notice_period_days' => 0,
            'allow_past_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (DB::table('personnel_types')->where('is_active', true)->pluck('id') as $personnelTypeId) {
            DB::table('leave_policies')->insert([
                'personnel_type_id' => $personnelTypeId,
                'leave_type_id' => $typeId,
                'effective_from' => today()->toDateString(),
                'annual_quota' => 0,
                'max_days_with_pay' => 0,
                'minimum_service_days' => 0,
                'notice_period_days' => 0,
                'allow_past_dates' => false,
                'exclude_holidays' => true,
                'allow_half_day' => false,
                'requires_substitute' => false,
                'is_carry_forward' => false,
                'max_carry_forward' => 0,
                'period_basis' => 'fiscal_year',
                'eligibility_rules' => json_encode(['gender' => 'female', 'requires_predecessor_leave_type_id' => (int) $maternityId]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** ถอนได้เฉพาะเมื่อยังไม่มีใบลาประเภทนี้ */
    public function down(): void
    {
        $typeId = DB::table('leave_types')->where('name', self::NAME)->value('id');
        if (! $typeId || DB::table('leaves')->where('leave_type_id', $typeId)->exists()) {
            return;
        }

        DB::table('leave_balances')->where('leave_type_id', $typeId)->delete();
        DB::table('leave_policies')->where('leave_type_id', $typeId)->delete();
        DB::table('leave_types')->where('id', $typeId)->delete();
    }
};
