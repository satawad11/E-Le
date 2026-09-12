<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เลิกใช้ตารางผลเทียบของ shadow mode (LEGACY-05 · 05-3)
 *
 * ตารางนี้มีไว้เพื่อรวบรวมหลักฐานก่อน cutover — เก็บผลของ workflow engine
 * ที่เดินคู่ขนานไว้เทียบกับตรรกะเดิม · เมื่อ engine เป็นผู้ตัดสินจริงแล้ว
 * (`workflow.engine_is_source_of_truth` = true) ไม่มีอะไรเดินคู่ขนานอีก
 * ตารางจึงมีแต่จะโตขึ้นเรื่อย ๆ โดยไม่มีใครอ่าน
 *
 * ข้อมูลในตารางเป็นบันทึกการทดลอง ไม่ใช่หลักฐานทางราชการของใบลาใด ๆ
 * ประวัติการอนุมัติจริงอยู่ที่ `leave_approvals`, `leave_audit_logs`
 * และ `workflow_actions` ครบถ้วนอยู่แล้ว การลบตารางนี้จึงไม่ทำให้
 * ข้อมูลของเรื่องใดหายไป
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workflow_shadow_comparisons');

        /*
         * ใบลาที่ค้างอยู่ตอน cutover มี instance ที่ยังติดธง "เดินคู่ขนาน" อยู่
         *
         * `LeaveApprovalCoordinator` ปลดธงให้เองตอนมีคนกดอนุมัติครั้งแรก แต่ระหว่าง
         * นั้นข้อมูลจะขัดกับความจริง — instance เหล่านี้เป็นตัวตัดสินจริงไปแล้ว
         * ตั้งแต่วินาทีที่เปิดสวิตช์ · ปลดให้ตรงกันเสียตั้งแต่ตอนนี้
         */
        if (Schema::hasTable('workflow_instances')) {
            DB::table('workflow_instances')
                ->where('is_shadow', true)
                ->update(['is_shadow' => false]);
        }
    }

    /**
     * สร้างตารางเปล่ากลับมาตามรูปเดิม — ผลเทียบที่เคยเก็บไว้ไม่ถูกกู้คืน
     * เพราะเป็นบันทึกการทดลองที่หมดประโยชน์ไปแล้ว
     */
    public function down(): void
    {
        Schema::create('workflow_shadow_comparisons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('checkpoint', 50);
            $table->json('legacy_result')->nullable();
            $table->json('engine_result')->nullable();
            $table->boolean('matched');
            $table->json('mismatch_details')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['matched', 'checkpoint']);
            $table->index('correlation_id');
        });
    }
};
