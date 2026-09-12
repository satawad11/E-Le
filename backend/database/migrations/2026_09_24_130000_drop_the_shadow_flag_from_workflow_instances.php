<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ถอดธง "instance ที่เดินคู่ขนานเพื่อทดสอบ" ออกจาก `workflow_instances`
 *
 * ธงนี้มีไว้สำหรับ shadow mode ซึ่งถูกถอดออกไปแล้วพร้อมการ cutover
 * (ดู `2026_09_24_110000_drop_the_workflow_shadow_comparison_log` และ
 * ADR-WORKFLOW-CUTOVER-20260912) · ทุกแถวถูกปลดเป็น false ไปแล้วในขานั้น
 * และไม่มีโค้ดใดตั้งเป็น true ได้อีก
 *
 * คอลัมน์ที่เหลืออยู่โดยไม่มีผู้เขียนเป็นกับดัก — `WorkQueueService` กรอง
 * `is_shadow = false` อยู่ ถ้ามีใครเผลอเขียน true กลับเข้ามา เรื่องนั้นจะหาย
 * จากคิวงานของเจ้าหน้าที่โดยไม่มีข้อความบอกอะไรเลย · ตัดทิ้งเสียดีกว่า
 * ปล่อยให้เป็นเงื่อนไขที่ไม่มีใครดูแล
 *
 * ไม่มีข้อมูลใดสูญหาย — ค่าที่ถูกลบคือ false ทั้งหมด
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_instances', 'is_shadow')) {
            return;
        }

        Schema::table('workflow_instances', function (Blueprint $table) {
            /*
             * ดัชนีนี้ตั้งไว้เพื่อค้นคิวงานด้วย (สถานะ + ไม่ใช่ของทดลอง)
             * เมื่อเงื่อนไขที่สองหายไป ดัชนีที่เหลือควรเป็นของ `status` ล้วน ๆ
             * และต้องถอดก่อน เพราะบางฐานข้อมูลไม่ยอมให้ลบคอลัมน์ที่ดัชนีอ้างถึง
             */
            $table->dropIndex(['status', 'is_shadow']);
            $table->dropColumn('is_shadow');
        });

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflow_instances', 'is_shadow')) {
            return;
        }

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->boolean('is_shadow')->default(false);
        });

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->index(['status', 'is_shadow']);
        });
    }
};
