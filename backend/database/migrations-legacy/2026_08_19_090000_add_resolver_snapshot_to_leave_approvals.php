<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผนึกวิธีหาตัวผู้อนุมัติลงใน snapshot ของใบลาแต่ละฉบับ
 *
 * เดิม `leave_approvals` เก็บเฉพาะ `required_role_id` ทำให้ขั้นตอนที่ตั้งค่าไว้เป็น
 * หัวหน้าแผนกต้นสังกัด / รอง ผอ. / ผอ. / ผู้ใช้เจาะจง ถูกลดรูปเหลือ "ตาม role"
 * ตอนยื่นใบลา ผู้อนุมัติตัวจริงจึงถูกปฏิเสธ 403 ในขั้นตอนของตัวเอง
 *
 * snapshot ต้องเก็บ "กติกา ณ วันยื่น" ให้ครบ เพื่อให้การแก้สายอนุมัติภายหลัง
 * ไม่ย้อนไปเปลี่ยนใบลาที่ยื่นไปแล้ว (กฎ effective-dated ของโครงการ)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_approvals', 'approver_type')) {
                $table->string('approver_type')->nullable()->after('required_role_id');
            }
            if (! Schema::hasColumn('leave_approvals', 'specific_user_id')) {
                $table->foreignId('specific_user_id')->nullable()->after('approver_type')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('leave_approvals', 'permission_name')) {
                $table->string('permission_name')->nullable()->after('specific_user_id');
            }
        });

        if (! Schema::hasTable('approval_steps')) {
            return;
        }

        /*
         * backfill เฉพาะใบที่ยัง "รออนุมัติ" อยู่จริง — ใบที่ตัดสินไปแล้วต้องคงเดิม
         * ใช้สายอนุมัติปัจจุบันเป็นค่าที่ดีที่สุดที่มี เพราะของเดิมไม่เคยบันทึกไว้
         */
        $steps = DB::table('approval_steps')->get();

        foreach ($steps as $step) {
            DB::table('leave_approvals')
                ->where('leave_approvals.step_order', $step->step_order)
                ->where('leave_approvals.status', 'Pending')
                ->whereIn('leave_approvals.leave_id', function ($query) {
                    $query->select('id')->from('leaves')->whereIn('status', ['Pending']);
                })
                ->update([
                    'approver_type' => $step->approver_type ?? 'ROLE',
                    'specific_user_id' => $step->specific_user_id ?? null,
                    'permission_name' => $step->permission_name ?? null,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('leave_approvals', 'permission_name')) {
                $table->dropColumn('permission_name');
            }
            if (Schema::hasColumn('leave_approvals', 'specific_user_id')) {
                $table->dropConstrainedForeignId('specific_user_id');
            }
            if (Schema::hasColumn('leave_approvals', 'approver_type')) {
                $table->dropColumn('approver_type');
            }
        });
    }
};
