<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ORG-ACCESS-02 · ทะเบียนตำแหน่งและกลุ่มบุคลากร (Q7 — docs/ORG-POSITION-ACCESS-MAPPING.md หัวข้อ 7.1)
 *
 * ข้อยกเว้น PROD PHASE ที่เจ้าของระบบอนุมัติ 2026-10-16: เพิ่มได้อย่างเดียว
 *   - ตารางใหม่ `position_groups`, `positions`
 *   - คอลัมน์ใหม่ `personnel_assignments.position_id` (nullable)
 * ไม่แก้/ลบคอลัมน์เดิม ข้อความตำแหน่ง `position` เดิมยังอยู่และยังเป็นข้อความที่พิมพ์ในแบบฟอร์ม
 *
 * ไม่เดาจากข้อความเดิม — `position_id` ของทุกคนเริ่มเป็น null
 * ให้งานบุคลากรจับคู่ด้วย `personnel:match-positions` หรือเลือกจากหน้าจัดการผู้ใช้
 *
 * ตำแหน่ง **ไม่ให้สิทธิ์ใด ๆ** ใช้แสดงผลและรายงานเท่านั้น
 * ชื่อกลุ่ม/ตำแหน่งตั้งต้นด้านล่างเป็นข้อมูล แก้หรือปิดใช้งานได้จากหน้าจอ
 */
return new class extends Migration
{
    private const GROUPS = [
        ['code' => 'EXECUTIVE', 'name' => 'ผู้บริหาร', 'sort_order' => 1],
        ['code' => 'TEACHER', 'name' => 'ครู', 'sort_order' => 2],
        ['code' => 'STAFF', 'name' => 'เจ้าหน้าที่', 'sort_order' => 3],
    ];

    private const POSITIONS = [
        'EXECUTIVE' => [
            ['DIRECTOR', 'ผู้อำนวยการ'],
            ['DEPUTY_RESOURCES', 'รองผู้อำนวยการฝ่ายบริหารทรัพยากร'],
            ['DEPUTY_STRATEGY', 'รองผู้อำนวยการฝ่ายยุทธศาสตร์และแผนงาน'],
            ['DEPUTY_STUDENT_AFFAIRS', 'รองผู้อำนวยการฝ่ายพัฒนากิจการนักเรียนนักศึกษา'],
            ['DEPUTY_ACADEMIC', 'รองผู้อำนวยการฝ่ายวิชาการ'],
        ],
        'TEACHER' => [
            ['TEACHER', 'ครู'],
            ['TEACHER_ASSISTANT', 'ครูผู้ช่วย'],
            ['TEACHER_GOV_EMPLOYEE', 'พนักงานราชการ (ครู)'],
            ['CONTRACT_TEACHER', 'ครูจ้างสอน'],
            ['HOURLY_TEACHER', 'ครูจ้างสอนรายชั่วโมง'],
        ],
        'STAFF' => [
            ['STAFF_PERSONNEL', 'เจ้าหน้าที่งานบุคลากร'],
            ['STAFF_ADMIN', 'เจ้าหน้าที่งานบริหารงานทั่วไป'],
            ['STAFF_SUPPLIES', 'เจ้าหน้าที่งานพัสดุ'],
            ['STAFF_FINANCE', 'เจ้าหน้าที่งานการเงิน'],
            ['STAFF_ACCOUNTING', 'เจ้าหน้าที่งานการบัญชี'],
            ['STAFF_REGISTRAR', 'เจ้าหน้าที่งานทะเบียน'],
            ['STAFF_GENERAL', 'เจ้าหน้าที่ทั่วไป'],
        ],
    ];

    public function up(): void
    {
        Schema::create('position_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('position_group_id')->constrained('position_groups')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['position_group_id', 'is_active']);
        });

        Schema::table('personnel_assignments', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('position')
                ->constrained('positions')->restrictOnDelete();
        });

        $now = now();
        foreach (self::GROUPS as $group) {
            $groupId = DB::table('position_groups')->insertGetId($group + [
                'uuid' => (string) Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (self::POSITIONS[$group['code']] as $index => [$code, $name]) {
                DB::table('positions')->insert([
                    'uuid' => (string) Str::uuid(),
                    'code' => $code,
                    'name' => $name,
                    'position_group_id' => $groupId,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('personnel_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
        Schema::dropIfExists('position_groups');
    }
};
