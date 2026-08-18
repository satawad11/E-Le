<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * เติมโครงสร้างหน่วยงานให้ครบหลัง departments ถูก seed แล้ว
 *
 * migration `2026_08_13_100000_create_core_data_foundation` ทำ backfill นี้อยู่แล้ว
 * แต่ตอน `migrate:fresh --seed` migration รัน **ก่อน** seeder ตาราง `departments`
 * จึงยังว่างเปล่า ผลคือระบบที่ติดตั้งใหม่ได้ org_units แค่ ORG-ROOT ตัวเดียว
 * `department_org_unit_map` ว่าง และผู้ใช้ทุกคนมี `org_unit_id` เป็น null
 *
 * ผลกระทบที่ตามมา: ทุกฟีเจอร์ที่ผูกกับ OrgScopeResolver ใช้งานไม่ได้
 * ที่เห็นชัดที่สุดคือคำสั่ง OT — `OvertimeOrderService::create()` เรียก
 * `canSeeOrgUnit()` กับ org unit ที่เป็น null ซึ่งคืน false เสมอ
 * ทำให้ **ไม่มีใครออกคำสั่ง OT ได้เลย แม้แต่ Super Admin**
 *
 * seeder นี้ใช้ตรรกะเดียวกับ migration ทุกประการ (code `DEPT-000001`, parent
 * เป็น ORG-ROOT, type `department`) และ idempotent — รันซ้ำไม่สร้างซ้ำ
 * และไม่ทับ org_unit_id ที่ผู้ดูแลตั้งไว้เองแล้ว
 */
class OrgUnitBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $rootId = DB::table('org_units')->where('code', 'ORG-ROOT')->value('id');

        if ($rootId === null) {
            $rootId = DB::table('org_units')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'parent_id' => null,
                'code' => 'ORG-ROOT',
                'name' => config('app.name', 'E-La'),
                'type' => 'organization',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('departments')->orderBy('id')->each(function (object $department) use ($rootId): void {
            $mapped = DB::table('department_org_unit_map')
                ->where('department_id', $department->id)
                ->value('org_unit_id');

            if ($mapped !== null) {
                return;
            }

            $code = 'DEPT-'.str_pad((string) $department->id, 6, '0', STR_PAD_LEFT);
            $orgUnitId = DB::table('org_units')->where('code', $code)->value('id');

            $orgUnitId ??= DB::table('org_units')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'parent_id' => $rootId,
                'code' => $code,
                'name' => $department->name,
                'type' => 'department',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_active' => (bool) ($department->is_active ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('department_org_unit_map')->insert([
                'department_id' => $department->id,
                'org_unit_id' => $orgUnitId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // เติมเฉพาะคนที่ยังไม่มี — ไม่ทับค่าที่ตั้งไว้แล้ว
        DB::table('users')
            ->whereNotNull('department_id')
            ->whereNull('org_unit_id')
            ->update([
                'org_unit_id' => DB::raw('(SELECT org_unit_id FROM department_org_unit_map WHERE department_org_unit_map.department_id = users.department_id)'),
            ]);
    }
}
