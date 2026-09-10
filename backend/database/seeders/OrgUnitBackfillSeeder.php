<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ยอดผังองค์กรต้องมีเสมอ
 *
 * **ที่มา:** seeder นี้เคยมีหน้าที่แปลง `departments` เป็น `org_units` หลัง seed
 * เพราะ migration ที่ทำ backfill รัน **ก่อน** seeder ตาราง `departments` จึงยังว่าง
 * ผลคือระบบที่ติดตั้งใหม่ได้ org_units แค่ ORG-ROOT ตัวเดียว และผู้ใช้ทุกคนมี
 * `org_unit_id` เป็น null ซึ่งทำให้ทุกฟีเจอร์ที่ผูกกับ `OrgScopeResolver` ใช้ไม่ได้
 * (ที่เห็นชัดที่สุดคือไม่มีใครออกคำสั่ง OT ได้เลย แม้แต่ Super Admin)
 *
 * ผังองค์กรเหลือชุดเดียวแล้ว — หน่วยงานถูกสร้างตรงเข้า `org_units` ตั้งแต่ต้น
 * จึงไม่มีอะไรให้แปลงอีก เหลือเพียงหน้าที่เดียวคือรับประกันว่ายอดผังมีอยู่จริง
 * เพราะทุกหน่วยงานที่สร้างจากหน้าจอจะแขวนอยู่ใต้มัน
 *
 * idempotent — รันซ้ำไม่สร้างซ้ำ และไม่ทับชื่อที่ผู้ดูแลตั้งไว้เอง
 */
class OrgUnitBackfillSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('org_units')->where('code', 'ORG-ROOT')->exists()) {
            return;
        }

        $institution = DB::table('settings')->whereIn('key', ['institution_name', 'school_name'])->value('value');

        DB::table('org_units')->insert([
            'uuid' => (string) Str::uuid(),
            'parent_id' => null,
            'code' => 'ORG-ROOT',
            // ชื่อสถานศึกษาถ้ามี — ไม่งั้นใช้ชื่อระบบไปก่อน แล้วหน้าตั้งค่าจะปรับให้เอง
            'name' => trim((string) $institution) ?: config('app.name', 'E-La'),
            'type' => 'organization',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
