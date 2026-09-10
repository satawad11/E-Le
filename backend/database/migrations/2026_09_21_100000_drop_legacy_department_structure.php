<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ผังองค์กรเหลือชุดเดียว — ถอดโครง `departments` ออก
 *
 * **ที่มา:** ระบบมีโครงหน่วยงานสองชุดอยู่พร้อมกันมาตลอด — `departments` แบบชั้นเดียว
 * ของเดิม กับ `org_units` แบบหลายชั้นที่การอนุมัติ ขอบเขตการมองเห็น ปฏิทิน และการหา
 * หัวหน้าใช้จริง · ทั้งสองถูกประคองให้ตรงกันด้วย `department_org_unit_map` และตัวซิงก์
 * สองทางใน `PersonnelSyncService` แต่ทุกครั้งที่สองชุดไม่ตรงกัน ผลที่ผู้ใช้เห็นคือ
 * เอกสารบอกหน่วยงานหนึ่ง ระบบเดินสายอนุมัติตามอีกหน่วยงานหนึ่ง และมีทั้งกรณี
 * ขอบเขตการมองเห็นรั่ว (แก้ไปแล้ว) และปฏิทินวันทำงานเพี้ยน (แก้ไปแล้ว)
 *
 * ทุกตัวอ่านถูกย้ายไป `org_units` ครบแล้ว รอบนี้จึงถอดของที่ไม่มีใครอ่านออก:
 *   - `users.department_id`
 *   - `department_org_unit_map`  (สะพานข้ามสองผัง)
 *   - `departments`
 *
 * ก่อนถอด จะเติม `users.org_unit_id` ให้ผู้ที่ยังว่างอยู่จากสะพานเป็นครั้งสุดท้าย
 * เพื่อไม่ให้ใครหลุดจากผังไปเงียบ ๆ
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillUsers();

        if (Schema::hasColumn('users', 'department_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('department_id');
            });
        }

        Schema::dropIfExists('department_org_unit_map');
        Schema::dropIfExists('departments');
    }

    /**
     * ผู้ใช้ที่ยังไม่มีหน่วยงานในผัง แต่มีแผนกเดิมอยู่ — เติมให้ก่อนสะพานหายไป
     *
     * ถ้าเติมไม่ได้ (ไม่มีคู่ในสะพาน) จะเหลือ `org_unit_id` เป็น null ซึ่งเป็นสถานะ
     * ที่หน้าตรวจความพร้อมของสายอนุมัติชี้ให้ผู้ดูแลเห็นอยู่แล้ว ดีกว่าเดาให้ผิดหน่วยงาน
     */
    private function backfillUsers(): void
    {
        if (! Schema::hasColumn('users', 'department_id') || ! Schema::hasTable('department_org_unit_map')) {
            return;
        }

        foreach (DB::table('department_org_unit_map')->get() as $pair) {
            DB::table('users')
                ->whereNull('org_unit_id')
                ->where('department_id', $pair->department_id)
                ->update(['org_unit_id' => $pair->org_unit_id]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('department_org_unit_map')) {
            Schema::create('department_org_unit_map', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('department_id')->unique()->constrained('departments')->cascadeOnDelete();
                $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'department_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            });
        }

        /*
         * ไม่สร้างข้อมูลแผนกคืน — ผังจริงอยู่ที่ `org_units` และการคัดลอกย้อนกลับ
         * จะสร้างสำเนาที่ไม่มีใครอ่านขึ้นมาใหม่ ซึ่งเป็นต้นเหตุของปัญหาที่เพิ่งแก้ไป
         */
    }
};
