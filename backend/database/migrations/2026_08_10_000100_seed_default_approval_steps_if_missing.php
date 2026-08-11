<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ระบบอนุมัติใบลาอ่านลำดับขั้นจาก approval_steps ถ้าตารางว่างจะอนุมัติใบลาไม่ได้เลย (404)
 * migration นี้เติมลำดับเริ่มต้นให้เฉพาะตอนที่ยังไม่มีข้อมูล — ถ้าผู้ดูแลตั้งค่าเองไว้แล้วจะไม่แตะ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('approval_steps')->exists()) return;

        $defaults = [
            ['HR Head', 'ตรวจสอบโดยหัวหน้าบุคลากร'],
            ['Deputy Director', 'พิจารณาโดยรองผู้อำนวยการ'],
            ['Director', 'อนุมัติรอบสุดท้ายโดยผู้อำนวยการ'],
        ];

        $now = now();
        $order = 0;
        foreach ($defaults as [$roleName, $stepName]) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (!$roleId) continue;
            DB::table('approval_steps')->insert([
                'role_id' => $roleId,
                'step_order' => ++$order,
                'name' => $stepName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // ไม่ลบย้อนกลับ — ลำดับการอนุมัติอาจถูกแก้ไขต่อโดยผู้ดูแลระบบแล้ว
    }
};
