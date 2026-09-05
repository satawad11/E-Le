<?php

use App\Models\Saraban\Document;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Database\Migrations\Migration;

/**
 * INT-08 — สายการเดินหนังสือ v1 (SoT §11, §12)
 *
 * **ใช้ workflow engine ของระบบเดิมทั้งหมด ไม่สร้าง engine ใหม่** ตาม SoT §11
 * ตารางที่ใช้คือ `workflow_definitions` / `workflow_versions` / `workflow_steps` /
 * `workflow_transitions` ซึ่งมีอยู่แล้วตั้งแต่ LEGACY-05
 *
 * ขั้นตอนผูกกับ **สิทธิ์** ไม่ใช่ชื่อบุคคลหรือชื่อบทบาท (SoT §11 "ห้าม hard-code คน")
 * แล้ว `SarabanAssigneeResolver` หรี่ผู้ถือสิทธิ์ให้เหลือเฉพาะผู้มีอำนาจกับหน่วยงาน
 * เจ้าของหนังสือฉบับนั้นอีกชั้นหนึ่ง
 *
 * สายนี้เป็นเวอร์ชันที่ **มีผลบังคับใช้ (active)** ตั้งแต่วันติดตั้ง — เรื่องที่เริ่ม
 * เดินแล้วจะถูก pin ไว้กับเวอร์ชันนี้ตลอดไป แม้ภายหลังจะเผยแพร่เวอร์ชันใหม่
 * (`workflow_instances.workflow_version_id` — SoT §12)
 */
return new class extends Migration
{
    private const DEFINITION_CODE = 'saraban.document';

    public function up(): void
    {
        $definition = WorkflowDefinition::firstOrCreate(
            ['code' => self::DEFINITION_CODE],
            [
                'module' => 'saraban',
                'name' => 'สายการเดินหนังสือ',
                'subject_type' => (new Document)->getMorphClass(),
                'description' => 'ตรวจโดยงานสารบรรณ แล้วเสนอผู้มีอำนาจลงนาม (INT-08)',
                'is_active' => true,
            ],
        );

        if ($definition->versions()->exists()) {
            return;
        }

        $version = WorkflowVersion::create([
            'workflow_definition_id' => $definition->id,
            'version' => 1,
            'status' => WorkflowVersion::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
            'notes' => 'เวอร์ชันตั้งต้นของงานสารบรรณ',
            'published_at' => now(),
        ]);

        /*
         * ขั้นที่ 1 — งานสารบรรณตรวจความถูกต้องก่อนเสนอ
         * ขั้นที่ 2 — ผู้มีอำนาจลงนาม (ขั้นสุดท้าย)
         *
         * ตั้งใจเริ่มด้วยสองขั้นที่สะท้อนงานจริงและทดสอบได้ครบ ไม่ใส่ขั้นที่ยัง
         * ไม่มีกติกายืนยัน (เช่น รองผู้อำนวยการ) เพราะจะกลายเป็นการเดาผังองค์กร
         * ของสถานศึกษา — เพิ่มขั้นภายหลังได้โดยเผยแพร่เวอร์ชันใหม่ ซึ่ง engine
         * รองรับอยู่แล้วและไม่กระทบเรื่องที่กำลังเดินอยู่
         */
        $review = WorkflowStep::create([
            'workflow_version_id' => $version->id,
            'code' => 'saraban_review',
            'name' => 'งานสารบรรณตรวจสอบ',
            'step_order' => 1,
            'type' => WorkflowStep::TYPE_APPROVAL,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.workflow.review',
            'completion_policy' => 'any',
            'is_final' => false,
        ]);

        $sign = WorkflowStep::create([
            'workflow_version_id' => $version->id,
            'code' => 'signature',
            'name' => 'ผู้มีอำนาจลงนาม',
            'step_order' => 2,
            'type' => WorkflowStep::TYPE_APPROVAL,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.signature.sign',
            'completion_policy' => 'any',
            'is_final' => true,
        ]);

        $transitions = [
            // เดินหน้า
            [$review, $sign, WorkflowTransition::APPROVE, 'ผ่านการตรวจ', null, false],
            [$sign, null, WorkflowTransition::APPROVE, 'ลงนาม', 'completed', false],

            // ส่งกลับแก้ไข — ต้องระบุเหตุผลเสมอ
            [$review, null, WorkflowTransition::RETURN, 'ส่งกลับแก้ไข', 'returned', true],
            [$sign, $review, WorkflowTransition::RETURN, 'ส่งกลับให้ตรวจใหม่', null, true],

            // ไม่เห็นชอบ
            [$review, null, WorkflowTransition::REJECT, 'ไม่ผ่านการตรวจ', 'rejected', true],
            [$sign, null, WorkflowTransition::REJECT, 'ไม่ลงนาม', 'rejected', true],
        ];

        foreach ($transitions as $index => [$from, $to, $action, $name, $result, $requiresComment]) {
            WorkflowTransition::create([
                'workflow_version_id' => $version->id,
                'from_step_id' => $from?->id,
                'to_step_id' => $to?->id,
                'action' => $action,
                'name' => $name,
                'result' => $result,
                'sort_order' => $index,
                'requires_comment' => $requiresComment,
            ]);
        }
    }

    public function down(): void
    {
        $definition = WorkflowDefinition::query()->where('code', self::DEFINITION_CODE)->first();

        if ($definition === null) {
            return;
        }

        // instance ต้องถูกลบก่อน version เพราะ foreign key เป็น restrictOnDelete
        foreach ($definition->versions()->get() as $version) {
            $version->instances()->delete();
            $version->delete();
        }

        $definition->delete();
    }
};
