<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ผู้ใช้ที่ถูกสร้างหลัง migration 2026_08_08_100100 ไม่มี personnel_type_id
 * ทำให้หานโยบายสิทธิการลาไม่เจอ และคำสั่งซิงค์โควตาข้ามคนเหล่านี้ทั้งหมด
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultId = DB::table('personnel_types')->where('code', 'civil_servant')->value('id');
        if (!$defaultId) return;

        DB::table('users')->whereNull('personnel_type_id')->update(['personnel_type_id' => $defaultId]);
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — ค่าเดิมคือ null ซึ่งเป็นสถานะที่ทำให้ระบบทำงานผิด
    }
};
