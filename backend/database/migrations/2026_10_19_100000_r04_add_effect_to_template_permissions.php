<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-04 — ชุดสิทธิ์ประกาศ "ปฏิเสธ" ได้ (Deny ชนะเสมอ · PROJECT_RULES ข้อ 4, 8)
 *
 * `effect = deny` + `scope` = เพดานที่ยังอนุญาต (เช่น `own` = เหลือเฉพาะข้อมูลตนเอง) · `scope` null = ปฏิเสธทั้งหมด
 * ใช้กับชุดสิทธิ์ผู้ดูแลระบบ/ผู้ตรวจสอบ ที่ต้องไม่เห็นข้อมูลบุคลากรของผู้อื่นแม้มีตำแหน่งในสายงาน
 * additive เท่านั้น — แถวเดิมเป็น allow ทั้งหมด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_permissions', function (Blueprint $table) {
            $table->string('effect', 10)->default('allow')->after('permission_id');
            $table->string('scope', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('template_permissions', function (Blueprint $table) {
            $table->dropColumn('effect');
        });
    }
};
