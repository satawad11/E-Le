<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESS-GOV-01 — ขั้นอนุมัติแบบ "หัวหน้าของหน่วยงานที่ระบุ" (ORG_UNIT_MANAGER)
 *
 * ตามคำตอบ Q3 ของเจ้าของระบบ สายลาจริงคือ งานบุคลากร → รองผู้อำนวยการฝ่ายบริหารทรัพยากร → ผู้อำนวยการ
 * รองฯ ขั้นที่ 2 จึงต้องหาจาก "หัวหน้าของฝ่ายบริหารทรัพยากร" ไม่ใช่จากต้นสังกัดของผู้ยื่น
 * (DIVISION_DIRECTOR หาจากหน่วยแม่ของต้นสังกัด ซึ่งได้รองฯ คนละคนตามฝ่ายของครู)
 *
 * additive เท่านั้น · approval_steps เก็บหน่วยงานเป้าหมาย · leave_approvals snapshot ค่าเดียวกัน ณ วันยื่น
 * restrict on delete — หน่วยงานที่ถูกอ้างในสายอนุมัติลบไม่ได้ (หน่วยงานปิดได้ด้วย is_active เท่านั้น)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('approval_steps', 'org_unit_id')) {
                $table->foreignId('org_unit_id')->nullable()->after('permission_name')->constrained('org_units')->restrictOnDelete();
            }
        });

        Schema::table('leave_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_approvals', 'org_unit_id')) {
                $table->foreignId('org_unit_id')->nullable()->after('permission_name')->constrained('org_units')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        foreach (['leave_approvals', 'approval_steps'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'org_unit_id')) {
                    $table->dropConstrainedForeignId('org_unit_id');
                }
            });
        }
    }
};
