<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-05 แก้ไขหลังตรวจ (Q11 · ADR-R-023) — `role_grants.granted_by` เป็น null ได้
 *
 * null มีความหมายเดียว: ชุดสิทธิ์ตั้งต้นที่สร้างด้วยคำสั่ง `access:bootstrap` บนเซิร์ฟเวอร์ (ไม่มีผู้ใช้ในระบบเป็นผู้มอบ)
 * — ดีกว่าบันทึกชื่อผู้มอบที่ไม่ได้มอบจริง · รายละเอียดผู้สั่งอยู่ใน permission_audit_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('granted_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('role_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('granted_by')->nullable(false)->change();
        });
    }
};
