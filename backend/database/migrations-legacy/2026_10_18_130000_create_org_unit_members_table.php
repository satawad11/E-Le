<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESS-GOV-01 · C1 — เจ้าหน้าที่ประจำงาน (ระเบียบฯ 2569 ข้อ 13 วรรคสอง)
 *
 * ผู้อำนวยการมอบหมายครู/บุคลากรเป็น "เจ้าหน้าที่ประจำงาน" ได้หลายงาน แต่ต้นสังกัด (personnel_assignments)
 * มีได้หน่วยเดียว ตารางนี้เก็บการประจำงานเพิ่มเติมตามคำสั่ง แยกจาก org_unit_managers
 * เพื่อไม่ให้เจ้าหน้าที่ประจำงานถูกนับเป็นผู้อนุมัติใบลา
 *
 * ผล: ขอบเขตข้อมูลแบบ UNIT/DESCENDANTS รวมหน่วยที่ประจำด้วย · ปิดด้วย effective_to ไม่ลบ
 * additive เท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('org_unit_members')) {
            return;
        }

        Schema::create('org_unit_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('order_number', 100);
            $table->date('order_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'effective_from', 'effective_to'], 'org_unit_member_user_effective');
            $table->index(['org_unit_id', 'effective_from', 'effective_to'], 'org_unit_member_unit_effective');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_unit_members');
    }
};
