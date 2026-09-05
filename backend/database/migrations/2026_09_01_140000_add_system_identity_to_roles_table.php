<?php

use App\Support\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-026 — ตัวระบุบทบาทระบบที่เปลี่ยนไม่ได้
 *
 * เพิ่มคอลัมน์อย่างเดียว ไม่แตะข้อมูลเดิมนอกจากการเติมคีย์ให้แถวที่มีอยู่แล้ว
 * โค้ดเดิมทุกบรรทัดยังทำงานได้เหมือนเดิม (backward-compatible)
 *
 * เหตุผลที่จำเป็นแม้อยู่ใน Architecture Freeze: ด่านป้องกันบทบาทหลักที่มีอยู่
 * ใช้ชื่อที่แสดงเป็นตัวระบุ และชื่อที่โค้ดเทียบ (`SuperAdmin`) ไม่ตรงกับชื่อจริง
 * ในฐานข้อมูล (`Super Admin`) ด่านจึงไม่ทำงาน — เข้าข่าย Critical Bug Fix
 * ตาม `PROJECT_RULES.md` · บันทึกไว้ที่ `docs/DECISION-LOG.md` (ADR-ROLE-01)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'system_key')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('system_key', 64)->nullable()->unique()->after('name');
            });
        }

        if (! Schema::hasColumn('roles', 'is_system')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_system')->default(false)->after('system_key');
            });
        }

        /*
         * จับคู่แถวเดิมเข้ากับคีย์ระบบด้วยชื่อที่เคยใช้ — ทำครั้งเดียวตรงนี้
         * หลังจากนี้ระบบอ้างถึงบทบาทด้วย `system_key` ไม่ใช่ชื่อที่แสดงอีก
         */
        SystemRoles::backfillExistingRoles();
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'is_system')) {
                $table->dropColumn('is_system');
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'system_key')) {
                $table->dropUnique(['system_key']);
                $table->dropColumn('system_key');
            }
        });
    }
};
