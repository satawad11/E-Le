<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ทะเบียนตำแหน่งให้ตรงระเบียบ สอศ. ว่าด้วยการบริหารสถานศึกษา พ.ศ. 2569 (REDESIGN-2569 เฟส 4.4 · D-04)
 *
 * 1. รองผู้อำนวยการเป็นตำแหน่งเดียว (ข้อ 40 (2)) — ฝ่ายที่ดูแลมาจากคำสั่งมอบหมาย (ข้อ 13: ผอ. มอบให้รองฯ
 *    คนหนึ่งดูแลฝ่ายเดียวหรือหลายฝ่ายก็ได้) ซึ่งเก็บใน org_unit_managers ไม่ใช่ในชื่อตำแหน่ง
 * 2. ครูจ้างสอน/ครูจ้างรายชั่วโมง/พนักงานราชการ (ครู) ไม่ใช่ตำแหน่งตามข้อ 40 แต่เป็นสถานภาพการจ้าง
 *    — เจ้าของระบบตัดสิน D-04 (18/10/2569): ครูจ้างใช้สิทธิ์เหมือนลูกจ้างชั่วคราว
 *    ให้บันทึกตำแหน่ง "ครูผู้สอน" (ข้อ 5 นิยามตามหน้าที่) และสถานภาพที่ประเภทบุคลากร
 *
 * ปิดใช้ตำแหน่งเดิม ไม่ลบ — การผูกตำแหน่งที่มีอยู่ยังแสดงเป็นประวัติ เลือกใหม่ไม่ได้
 * ตำแหน่งเจ้าหน้าที่ประจำงาน (การเงิน พัสดุ ฯลฯ) คงไว้จนกว่าจะนำเข้าข้อมูลบุคลากรและหน้าที่ประจำงาน
 */
return new class extends Migration
{
    private const RETIRED = [
        'DEPUTY_RESOURCES', 'DEPUTY_STRATEGY', 'DEPUTY_STUDENT_AFFAIRS', 'DEPUTY_ACADEMIC',
        'TEACHER_GOV_EMPLOYEE', 'CONTRACT_TEACHER', 'HOURLY_TEACHER',
    ];

    /** รหัส => [ชื่อ, รหัสกลุ่ม, ลำดับ] */
    private const ADDED = [
        'DEPUTY_DIRECTOR' => ['รองผู้อำนวยการ', 'EXECUTIVE', 2],
        'TEACHER_INSTRUCTOR' => ['ครูผู้สอน', 'TEACHER', 3],
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('positions')) {
            return;
        }

        foreach (self::ADDED as $code => [$name, $group, $order]) {
            $groupId = DB::table('position_groups')->where('code', $group)->value('id');
            if (! $groupId || DB::table('positions')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('positions')->insert([
                'uuid' => (string) Str::uuid(), 'code' => $code, 'name' => $name, 'position_group_id' => $groupId,
                'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('positions')->whereIn('code', self::RETIRED)->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('positions')) {
            return;
        }

        DB::table('positions')->whereIn('code', self::RETIRED)->update(['is_active' => true, 'updated_at' => now()]);

        // ลบเฉพาะตำแหน่งใหม่ที่ยังไม่มีใครผูก — ที่ผูกแล้วปิดใช้แทน เพื่อไม่ให้ประวัติหลุด
        foreach (array_keys(self::ADDED) as $code) {
            $id = DB::table('positions')->where('code', $code)->value('id');
            if (! $id) {
                continue;
            }
            DB::table('personnel_assignments')->where('position_id', $id)->exists()
                ? DB::table('positions')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()])
                : DB::table('positions')->where('id', $id)->delete();
        }
    }
};
