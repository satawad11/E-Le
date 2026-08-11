<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ระเบียบว่าด้วยการลาของข้าราชการ: ลาป่วยได้รับเงินเดือนระหว่างลาปีหนึ่งไม่เกิน 60 วันทำการ
 * และถ้าผู้บังคับบัญชาเห็นสมควร อนุญาตให้ลาต่อได้อีกไม่เกิน 60 วันทำการโดยไม่ได้รับเงินเดือน
 *
 * เดิมระบบมีตัวเลขเดียว (annual_quota = 60) จึงบล็อกตั้งแต่วันที่ 61 ทำให้ลาส่วนที่ไม่รับเงินเดือนไม่ได้
 * แก้เป็น annual_quota = เพดานรวม, max_days_with_pay = ส่วนที่ได้รับเงินเดือน
 */
return new class extends Migration
{
    public function up(): void
    {
        $sickTypeIds = DB::table('leave_types')->where('name', 'like', '%ป่วย%')->pluck('id');
        if ($sickTypeIds->isEmpty()) return;

        // เพดานรวม = สองเท่าของส่วนที่ได้รับเงินเดือน (60 + 60, 30 + 30, 15 + 15 ตามประเภทบุคลากร)
        DB::table('leave_policies')
            ->whereIn('leave_type_id', $sickTypeIds)
            ->where('is_active', true) // นโยบายที่ปิดใช้งานแล้วไม่ต้องแตะ
            ->whereNotNull('annual_quota')
            ->whereNull('max_days_with_pay')
            ->update([
                'max_days_with_pay' => DB::raw('annual_quota'),
                'annual_quota' => DB::raw('annual_quota * 2'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $sickTypeIds = DB::table('leave_types')->where('name', 'like', '%ป่วย%')->pluck('id');
        if ($sickTypeIds->isEmpty()) return;

        DB::table('leave_policies')
            ->whereIn('leave_type_id', $sickTypeIds)
            ->whereNotNull('max_days_with_pay')
            ->update([
                'annual_quota' => DB::raw('max_days_with_pay'),
                'max_days_with_pay' => null,
                'updated_at' => now(),
            ]);
    }
};
