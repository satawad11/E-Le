<?php

use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * สายการเดินหนังสือ v2 — เพิ่มขั้น "รองผู้อำนวยการพิจารณา"
 *
 * v1 มีสองขั้น (งานสารบรรณตรวจ → ผู้มีอำนาจลงนาม) และเขียนเหตุผลไว้ว่าไม่ใส่ขั้น
 * รองผู้อำนวยการเพราะจะเป็นการเดาผังองค์กร · เจ้าของระบบยืนยันแล้วว่าสายจริงคือ
 * สร้าง → ตรวจ → รอง ผอ. → ผอ. จึงเผยแพร่เป็นเวอร์ชันใหม่
 *
 * **เรื่องที่กำลังเดินอยู่ไม่ถูกกระทบ** — `workflow_instances` pin เวอร์ชันของ
 * ตัวเองไว้ (SoT §12) หนังสือที่เสนอไปแล้วบน v1 จะเดินจบบน v1 ส่วนเรื่องใหม่
 * เริ่มบน v2 · นี่คือเหตุผลที่ต้องเผยแพร่เวอร์ชันใหม่แทนการแก้ v1 ในที่เดิม
 *
 * ขั้นใหม่ผูกกับสิทธิ์ `saraban.workflow.approve` **ไม่ใช่ชื่อบทบาท** ตาม SoT §11
 * ผู้ดูแลมอบหมายสิทธิ์นี้ให้ตำแหน่งที่ทำหน้าที่นี้จริงในสถานศึกษา ซึ่งอาจไม่ได้
 * ชื่อ "รองผู้อำนวยการ" ทุกแห่ง
 */
return new class extends Migration
{
    private const DEFINITION_CODE = 'saraban.document';

    public function up(): void
    {
        // ชื่อสิทธิ์ต้องมีอยู่ก่อน มิฉะนั้นขั้นใหม่จะไม่มีใครรับผิดชอบได้เลย
        (new PermissionCatalogSeeder)->run();

        $definition = WorkflowDefinition::query()->where('code', self::DEFINITION_CODE)->first();

        if ($definition === null) {
            return;
        }

        // เผยแพร่ซ้ำไม่ได้ — ระบบที่ได้ v2 ไปแล้วต้องไม่ได้ v3 ที่หน้าตาเหมือนกัน
        if ($definition->versions()->where('version', 2)->exists()) {
            return;
        }

        $previous = $definition->versions()->where('status', WorkflowVersion::STATUS_ACTIVE)->get();

        $version = WorkflowVersion::create([
            'workflow_definition_id' => $definition->id,
            'version' => 2,
            'status' => WorkflowVersion::STATUS_ACTIVE,
            'effective_from' => now()->toDateString(),
            'notes' => 'เพิ่มขั้นรองผู้อำนวยการพิจารณาก่อนเสนอลงนาม',
            'published_at' => now(),
        ]);

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

        $deputy = WorkflowStep::create([
            'workflow_version_id' => $version->id,
            'code' => 'deputy_review',
            'name' => 'รองผู้อำนวยการพิจารณา',
            'step_order' => 2,
            'type' => WorkflowStep::TYPE_APPROVAL,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.workflow.approve',
            'completion_policy' => 'any',
            'is_final' => false,
        ]);

        $sign = WorkflowStep::create([
            'workflow_version_id' => $version->id,
            'code' => 'signature',
            'name' => 'ผู้มีอำนาจลงนาม',
            'step_order' => 3,
            'type' => WorkflowStep::TYPE_APPROVAL,
            'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
            'assignee_permission' => 'saraban.signature.sign',
            'completion_policy' => 'any',
            'is_final' => true,
        ]);

        /*
         * ส่งกลับจากขั้นกลางไปยัง **ขั้นก่อนหน้า** ไม่ใช่จบเรื่อง — รอง ผอ. ที่เห็นว่า
         * เอกสารไม่ครบ ต้องการให้งานสารบรรณแก้แล้วเสนอใหม่ ไม่ใช่ตีเรื่องตกทั้งเรื่อง
         */
        $transitions = [
            [$review, $deputy, WorkflowTransition::APPROVE, 'ผ่านการตรวจ เสนอรอง ผอ.', null, false],
            [$deputy, $sign, WorkflowTransition::APPROVE, 'เห็นชอบ เสนอลงนาม', null, false],
            [$sign, null, WorkflowTransition::APPROVE, 'ลงนาม', 'completed', false],

            [$review, null, WorkflowTransition::RETURN, 'ส่งกลับแก้ไข', 'returned', true],
            [$deputy, $review, WorkflowTransition::RETURN, 'ส่งกลับให้ตรวจใหม่', null, true],
            [$sign, $deputy, WorkflowTransition::RETURN, 'ส่งกลับให้รอง ผอ. พิจารณาใหม่', null, true],

            [$review, null, WorkflowTransition::REJECT, 'ไม่ผ่านการตรวจ', 'rejected', true],
            [$deputy, null, WorkflowTransition::REJECT, 'ไม่เห็นชอบ', 'rejected', true],
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

        /*
         * ปลดเวอร์ชันเดิมออกจากการใช้งาน **หลัง** สร้างเวอร์ชันใหม่เสร็จ
         * ถ้าปลดก่อน จะมีช่วงหนึ่งที่ไม่มีเวอร์ชันใดใช้งานได้ และเรื่องที่เสนอ
         * ในช่วงนั้นจะเริ่มสายไม่ได้
         */
        foreach ($previous as $old) {
            $old->update([
                'status' => WorkflowVersion::STATUS_RETIRED,
                'effective_to' => now()->toDateString(),
            ]);
        }
    }

    public function down(): void
    {
        // ไม่ย้อนกลับ — เรื่องที่เดินอยู่บน v2 จะไม่มีเวอร์ชันรองรับ
    }
};
