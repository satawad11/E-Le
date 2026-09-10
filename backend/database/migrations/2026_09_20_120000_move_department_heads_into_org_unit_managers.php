<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "หัวหน้าแผนก" ย้ายจากฟิลด์ที่ไม่มีใครอ่าน มาเป็นการมอบหมายจริง
 *
 * **ที่มา:** `departments.head_of_department_id` ตั้งค่าได้จากหน้าจัดการหน่วยงาน
 * แต่ค้นทั้งโค้ดแล้วไม่มีตรรกะใดอ่านค่านี้เลย — มีแค่ validation กับ relation
 * ในโมเดล · ผู้ดูแลที่ตั้งหัวหน้าแผนกไว้แล้วรอให้ใบลาวิ่งไปหาเขา จะไม่มีวันเกิดขึ้น
 * และไม่มีข้อความใดบอก ซึ่งแย่กว่าการไม่มีช่องให้กรอกเสียอีก เพราะสร้างความมั่นใจผิด
 *
 * ที่ระบบใช้จริงคือ `org_unit_managers` (LEGACY-04 Decision 3) ซึ่งมีวันมีผล
 * มีผู้รักษาการ และเป็นสิ่งที่ `ApproverResolverService` อ่านเพื่อหาหัวหน้าของผู้ยื่น
 *
 * migration นี้จึง **ย้ายเจตนาของผู้ดูแลไปไว้ที่ที่มีผลจริง** ก่อนจะถอดฟิลด์ตายทิ้ง
 * ไม่ทับการมอบหมายที่มีอยู่แล้ว และไม่แตะหน่วยงานที่ยังไม่เคยตั้งหัวหน้า
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departments') || ! Schema::hasColumn('departments', 'head_of_department_id')) {
            return;
        }

        if (Schema::hasTable('org_unit_managers') && Schema::hasTable('department_org_unit_map')) {
            $this->carryHeadsOver();
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('head_of_department_id');
        });
    }

    private function carryHeadsOver(): void
    {
        $today = now()->toDateString();

        $heads = DB::table('departments')
            ->whereNotNull('head_of_department_id')
            ->pluck('head_of_department_id', 'id');

        foreach ($heads as $departmentId => $userId) {
            $orgUnitId = DB::table('department_org_unit_map')
                ->where('department_id', $departmentId)
                ->value('org_unit_id');

            if ($orgUnitId === null) {
                continue;
            }

            // มีผู้รับผิดชอบที่มีผลอยู่แล้ว = ข้อมูลในผังใหม่กว่า อย่าไปทับ
            $alreadyManaged = DB::table('org_unit_managers')
                ->where('org_unit_id', $orgUnitId)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->exists();

            if ($alreadyManaged) {
                continue;
            }

            DB::table('org_unit_managers')->insert([
                'uuid' => (string) Str::uuid(),
                'org_unit_id' => $orgUnitId,
                'user_id' => $userId,
                'assignment_type' => 'MANAGER',
                'scope' => 'UNIT',
                'effective_from' => $today,
                'effective_to' => null,
                'note' => 'ย้ายมาจากช่อง "หัวหน้าแผนก" เดิมของหน้าจัดการหน่วยงาน',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('departments') || Schema::hasColumn('departments', 'head_of_department_id')) {
            return;
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignId('head_of_department_id')->nullable()->constrained('users')->nullOnDelete();
        });

        /*
         * ไม่เติมค่ากลับ — การมอบหมายในผังเป็นข้อมูลที่มีผลจริงและอาจถูกแก้ไปแล้ว
         * การคัดลอกย้อนกลับจะสร้างสำเนาที่ไม่มีใครอ่านขึ้นมาใหม่โดยไม่มีประโยชน์
         */
    }
};
