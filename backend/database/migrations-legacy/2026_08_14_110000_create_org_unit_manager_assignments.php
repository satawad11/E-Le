<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-04 (ปิดงาน) · Decision 3 — หัวหน้าแผนก / หัวหน้างาน / หัวหน้าฝ่าย / ผู้รักษาการ
 *
 * เจ้าของระบบสั่งไว้ชัดเจนว่า **ห้ามสร้าง role ชื่อ "หัวหน้าแผนก" เพื่อใช้เป็น
 * business logic** เพราะชื่อ role แก้ได้จากหน้าจอและที่ผ่านมาชื่อในโค้ดกับชื่อจริง
 * ในฐานข้อมูลก็ไม่ตรงกันอยู่แล้ว (LEGACY-04-REPORT ข้อ 2)
 *
 * "ใครเป็นหัวหน้าของหน่วยงานไหน" จึงเป็น **ข้อมูลการมอบหมาย** ไม่ใช่ชื่อบทบาท
 * เก็บเป็น assignment ผูกกับ `org_units` ที่ LEGACY-03 สร้างไว้
 *
 * รองรับตามที่ Decision 3 กำหนด:
 *   - effective date (`effective_from` / `effective_to`) — ไม่ลบของเก่าทับ
 *     ตาม PROJECT_RULES ข้อ 7 และข้อห้าม "overwrite ประวัติ effective-dated"
 *   - ผู้รักษาการ / ผู้รับมอบอำนาจ ผ่าน `assignment_type`
 *   - ขอบเขตเริ่มต้น UNIT และเป็น DESCENDANTS เมื่อหน่วยงานมีหน่วยงานลูก
 *
 * ตารางนี้ถูกสร้างว่างเปล่า — ไม่มีใครได้หรือเสียสิทธิ์จาก migration นี้
 * `OrgScopeResolver` จะใช้ข้อมูลนี้ก็ต่อเมื่อมีการมอบหมายจริงเท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit_managers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            /*
             * MANAGER   หัวหน้าตัวจริงของหน่วยงาน
             * ACTING    ผู้รักษาการแทน (เช่น หัวหน้าลา/ตำแหน่งว่าง)
             * DELEGATE  ผู้รับมอบอำนาจเฉพาะช่วงเวลา
             */
            $table->string('assignment_type', 20)->default('MANAGER');

            /*
             * ขอบเขตที่การเป็นหัวหน้านี้ให้ — UNIT หรือ DESCENDANTS ตาม Decision 3
             * ใช้ค่าเดียวกับ OrgScopeResolver เพื่อไม่ให้มีคำศัพท์สองชุด
             */
            $table->string('scope', 20)->default('UNIT');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['org_unit_id', 'effective_from', 'effective_to'], 'org_unit_manager_effective');
            $table->index(['user_id', 'effective_from', 'effective_to'], 'org_unit_manager_user_effective');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_unit_managers');
    }
};
