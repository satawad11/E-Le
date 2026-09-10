<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ยอดผังองค์กรใช้ชื่อสถานศึกษา ไม่ใช่ชื่อระบบ
 *
 * `ORG-ROOT` ถูกสร้างพร้อมชื่อจาก `config('app.name')` ตอนวางรากฐานข้อมูล เพราะ
 * ขณะนั้นยังไม่มีใครกรอกชื่อสถานศึกษา ระบบที่ติดตั้งแล้วจึงมียอดผังชื่อ "Laravel"
 * หรือ "E-La" ค้างอยู่ · ชื่อนี้ไม่ได้อยู่แต่ในหลังบ้าน — มันไหลไปโผล่ในรายงานที่
 * จัดกลุ่มตามผัง และเกือบไปโผล่ในช่อง "สังกัด" ของใบลาที่พิมพ์
 *
 * ตั้งชื่อให้ตรงกับค่าที่ผู้ดูแลกรอกไว้แล้ว และตั้งแต่นี้ไปการบันทึกหน้าตั้งค่าจะ
 * ปรับชื่อยอดผังตามให้เอง (`SystemSettingService::syncOrganisationRootName`)
 *
 * ไม่แตะยอดผังที่ถูกตั้งชื่อไว้เป็นอย่างอื่นแล้ว — ถือว่าผู้ดูแลตั้งใจ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('org_units') || ! Schema::hasTable('settings')) {
            return;
        }

        $institution = DB::table('settings')->whereIn('key', ['institution_name', 'school_name'])
            ->orderByRaw("CASE WHEN \"key\" = 'institution_name' THEN 0 ELSE 1 END")
            ->value('value');

        $institution = trim((string) $institution);

        if ($institution === '') {
            return;
        }

        DB::table('org_units')
            ->where('code', 'ORG-ROOT')
            // แตะเฉพาะยอดผังที่ยังใช้ชื่อระบบอยู่ ไม่ทับชื่อที่ผู้ดูแลตั้งเอง
            ->whereIn('name', array_unique([config('app.name'), 'Laravel', 'E-La']))
            ->update(['name' => $institution, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // ชื่อที่ถูกต้องกว่าเดิม ไม่มีเหตุผลให้ย้อนกลับไปเป็นชื่อระบบ
    }
};
