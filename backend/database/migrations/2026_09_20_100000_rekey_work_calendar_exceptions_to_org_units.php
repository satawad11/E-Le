<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * วันหยุด/วันทำงานชดเชยเฉพาะหน่วยงาน ย้ายมาผูกกับผังจริง
 *
 * **ที่มา:** `work_calendar_exceptions` เป็นที่สุดท้ายที่ยัง *ตัดสินใจ* ด้วย
 * `departments` ซึ่งเป็นโครงหน่วยงานชั้นเดียวของเดิม ขณะที่ทั้งระบบ — การอนุมัติ
 * ขอบเขตการมองเห็น การหาหัวหน้า — ใช้ `org_units` ที่เป็นผังหลายชั้น
 * `TimeService` จึงต้องแปลง org_unit กลับเป็น department ผ่าน `department_org_unit_map`
 * ทุกครั้งที่ถามว่า "วันนี้เป็นวันทำงานของคนนี้ไหม" และผลจะเพี้ยนทันทีที่ผังสองชุด
 * ไม่ตรงกัน หรือเมื่อหน่วยงานนั้นไม่มีคู่ใน `departments` (หน่วยงานที่สร้างในผังใหม่)
 *
 * ผลพลอยได้ที่สำคัญ: ผูกกับผังแล้ว **ประกาศที่ฝ่ายมีผลถึงงานที่อยู่ใต้ฝ่ายนั้น**
 * ซึ่งเป็นสิ่งที่ผู้ดูแลคาดหวังอยู่แล้วแต่โครงเก่าทำไม่ได้เพราะไม่มีลำดับชั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_calendar_exceptions')) {
            return;
        }

        if (! Schema::hasColumn('work_calendar_exceptions', 'org_unit_id')) {
            Schema::table('work_calendar_exceptions', function (Blueprint $table): void {
                $table->foreignId('org_unit_id')->nullable()->after('date')
                    ->constrained('org_units')->nullOnDelete();
                // หนึ่งประกาศต่อหนึ่งวันต่อหนึ่งหน่วยงาน — เงื่อนไขเดียวกับที่ตารางเดิมบังคับไว้
                $table->unique(['date', 'org_unit_id'], 'work_calendar_date_org_unit_unique');
            });
        }

        /*
         * ย้ายข้อมูลเดิมผ่านสะพานที่ระบบใช้ประคองสองผังอยู่แล้ว
         *
         * แถวที่หาคู่ในผังไม่เจอจะเหลือ `org_unit_id` เป็น null ซึ่งแปลว่า
         * "มีผลทั้งองค์กร" — กว้างกว่าเดิมแต่ไม่ทำให้ใครเสียวันหยุดที่เคยมี
         * และเป็นผลลัพธ์ที่ผู้ดูแลเห็นได้จากหน้าจอ ไม่ใช่ข้อมูลที่หายเงียบ ๆ
         */
        if (Schema::hasColumn('work_calendar_exceptions', 'department_id') && Schema::hasTable('department_org_unit_map')) {
            foreach (DB::table('department_org_unit_map')->get() as $pair) {
                DB::table('work_calendar_exceptions')
                    ->where('department_id', $pair->department_id)
                    ->update(['org_unit_id' => $pair->org_unit_id]);
            }
        }

        if (Schema::hasColumn('work_calendar_exceptions', 'department_id')) {
            Schema::table('work_calendar_exceptions', function (Blueprint $table): void {
                /*
                 * ต้องถอด unique เดิมก่อนถอดคอลัมน์ — SQLite ปฏิเสธการลบคอลัมน์
                 * ที่ยังมี index อ้างถึงอยู่ (MySQL ลบ index ให้เองแต่เราสั่งให้ชัด
                 * เพื่อให้ทั้งสองฐานข้อมูลเดินทางเดียวกัน)
                 */
                $table->dropUnique('work_calendar_exceptions_date_department_id_unique');
                $table->dropConstrainedForeignId('department_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_calendar_exceptions')) {
            return;
        }

        if (! Schema::hasColumn('work_calendar_exceptions', 'department_id')) {
            Schema::table('work_calendar_exceptions', function (Blueprint $table): void {
                $table->foreignId('department_id')->nullable()->after('date')
                    ->constrained('departments')->nullOnDelete();
            });

            if (Schema::hasTable('department_org_unit_map')) {
                foreach (DB::table('department_org_unit_map')->get() as $pair) {
                    DB::table('work_calendar_exceptions')
                        ->where('org_unit_id', $pair->org_unit_id)
                        ->update(['department_id' => $pair->department_id]);
                }
            }
        }

        if (Schema::hasColumn('work_calendar_exceptions', 'org_unit_id')) {
            Schema::table('work_calendar_exceptions', function (Blueprint $table): void {
                $table->dropIndex('work_calendar_date_org_unit');
                $table->dropConstrainedForeignId('org_unit_id');
            });
        }
    }
};
