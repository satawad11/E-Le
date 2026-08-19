<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * แยก "สายอนุมัติใบลา" กับ "สายอนุมัติคำขอยกเลิกใบลา" ออกจากกันในตารางเดียว
 *
 * ก่อนหน้านี้คำขอยกเลิกใช้ขั้นตอนชุดเดียวกับการขอลาเสมอ (ADR-APPROVAL-04)
 * ซึ่งใช้ได้กับหน่วยงานส่วนใหญ่ แต่บางแห่งต้องการให้การยกเลิกสั้นกว่า
 * เช่นเสนอผู้อำนวยการคนเดียวโดยไม่ต้องผ่านหัวหน้าแผนกอีกรอบ
 *
 * ข้อตกลงสำคัญ: **สายยกเลิกที่ยังไม่ได้ตั้งค่า = ใช้สายของใบลา**
 * ระบบที่ติดตั้งอยู่แล้วจึงทำงานเหมือนเดิมทุกประการจนกว่าผู้ดูแลจะตั้งค่าเอง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('approval_steps', 'chain')) {
                $table->string('chain', 20)->default('leave')->after('id');
                $table->index(['chain', 'step_order'], 'approval_steps_chain_order_index');
            }
        });

        // ขั้นตอนที่มีอยู่เดิมทั้งหมดคือสายของการขอลา
        DB::table('approval_steps')->whereNull('chain')->update(['chain' => 'leave']);
    }

    public function down(): void
    {
        DB::table('approval_steps')->where('chain', 'cancellation')->delete();

        Schema::table('approval_steps', function (Blueprint $table) {
            if (Schema::hasColumn('approval_steps', 'chain')) {
                $table->dropIndex('approval_steps_chain_order_index');
                $table->dropColumn('chain');
            }
        });
    }
};
