<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เรื่องหนึ่งเดินสายเดียวกันได้มากกว่าหนึ่งรอบ
 *
 * **ที่มา:** `workflow_instances` มี unique `(subject_type, subject_id, workflow_version_id)`
 * ซึ่งแปลว่า "หนึ่งเรื่องเดินได้รอบเดียวต่อเวอร์ชัน" · ในความเป็นจริงมีกรณีที่ต้องเดินซ้ำ
 * บนเวอร์ชันเดิม — **คำขอยกเลิกใบลาที่ถูกปฏิเสธแล้วผู้ยื่นยื่นใหม่**
 *
 * ตรรกะเดิมไม่เจอปัญหานี้เพราะมันลบแถวสรุปของรอบก่อนแล้วสร้างชุดใหม่ทุกครั้ง
 * แต่ engine เก็บ instance และ action log ไว้เป็นหลักฐาน จึงลบทิ้งไม่ได้และ
 * สร้างรอบใหม่ก็ไม่ได้ ผลคือพอ cutover แล้วผู้อนุมัติได้ข้อความว่า "ไม่มีขั้นตอน
 * ที่รออนุมัติอยู่" ทั้งที่ผู้ยื่นเพิ่งยื่นคำขอใหม่มาหมาด ๆ
 *
 * เพิ่ม `run_no` แล้วย้าย unique ไปคุม `(subject, version, run_no)` แทน — ประวัติ
 * ของรอบก่อนยังอยู่ครบ และรอบใหม่เป็นคนละ instance ที่ตรวจสอบย้อนหลังได้
 *
 * แถวที่มีอยู่ทั้งหมดเป็นรอบที่ 1 จึงไม่มีใครได้หรือเสียอะไรจาก migration นี้
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_instances') || Schema::hasColumn('workflow_instances', 'run_no')) {
            return;
        }

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->unsignedInteger('run_no')->default(1)->after('workflow_version_id');
        });

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropUnique('workflow_instance_subject_unique');
            $table->unique(
                ['subject_type', 'subject_id', 'workflow_version_id', 'run_no'],
                'workflow_instance_subject_run_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('workflow_instances', 'run_no')) {
            return;
        }

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropUnique('workflow_instance_subject_run_unique');
        });

        /*
         * คืน unique เดิมได้ก็ต่อเมื่อไม่มีเรื่องใดเดินเกินหนึ่งรอบ — ถ้ามี การบังคับ
         * unique เดิมจะล้ม ซึ่งถูกต้องแล้ว เพราะข้อมูลนั้นอยู่ในรูปที่โครงเดิมรองรับไม่ได้
         */
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->unique(['subject_type', 'subject_id', 'workflow_version_id'], 'workflow_instance_subject_unique');
            $table->dropColumn('run_no');
        });
    }
};
