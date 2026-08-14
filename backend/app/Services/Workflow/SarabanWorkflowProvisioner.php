<?php

namespace App\Services\Workflow;

use App\Models\Saraban\Document;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-05 · 05-5 — เตรียมงานสารบรรณเข้า Workflow Engine เดียวกัน
 *
 * **สถานะ: draft เท่านั้น — ยังไม่มีผลกับระบบจริง**
 *
 * `DocumentWorkflowService` ยังเป็นผู้ตัดสินสถานะหนังสือทั้งหมดเหมือนเดิม
 * ไฟล์นี้แค่บันทึกว่า "สายงานของสารบรรณที่ใช้อยู่ หน้าตาเป็นอย่างไร"
 * ในรูปแบบของ engine เพื่อให้ขั้นตอน integration จริงมีจุดตั้งต้นที่ตรวจสอบได้
 *
 * เหตุผลที่ยังไม่เปิดใช้:
 *   - โจทย์ของเฟสนี้สั่งไว้ชัดว่า **ห้ามเปลี่ยน business permission ที่ปิดไว้
 *     ใน LEGACY-04** โดยเฉพาะสิทธิ์ของ Document Officer / ผอ. / รอง ผอ.
 *     สิทธิ์ลงนาม และสิทธิ์เอกสารลับ
 *   - LEGACY-04 ปิดงานโดย **ยังไม่ได้** ให้สิทธิ์ `saraban.document.approve`
 *     และ `.sign` แก่ ผอ./รอง ผอ. (รอการตัดสินใจ) ถ้าเดินสายนี้จริงตอนนี้
 *     จะไม่มีผู้อนุมัติในสายเลยนอกจาก Super Admin
 *
 * สายงานที่บันทึกไว้สะท้อน `DocumentWorkflowService` ตามจริง:
 *
 *     draft ──submit──▶ pending_review ──approve──▶ pending_approval
 *           ◀──reject──                ◀──reject──
 *                                                 ──approve──▶ approved_pending_signature
 *                                                 ──sign─────▶ signed
 */
class SarabanWorkflowProvisioner
{
    public const DEFINITION_CODE = 'saraban.document';

    /**
     * ขั้นตอน => [ชื่อ, permission ที่คุมขั้นนั้นอยู่จริงวันนี้]
     *
     * ยึดตาม middleware ของ route สารบรรณ ณ วันที่เขียน (หลัง LEGACY-04)
     * ไม่ได้เปลี่ยนแปลงสิทธิ์ใด ๆ เพียงบันทึกว่าใครคุมขั้นไหน
     */
    private const STEPS = [
        ['code' => 'draft', 'name' => 'ร่างหนังสือ', 'permission' => 'saraban.document.create'],
        ['code' => 'pending_review', 'name' => 'รอตรวจสอบโดยเจ้าหน้าที่สารบรรณ', 'permission' => 'saraban.document.register'],
        ['code' => 'pending_approval', 'name' => 'รอกลั่นกรอง/อนุมัติ', 'permission' => 'saraban.document.approve'],
        ['code' => 'approved_pending_signature', 'name' => 'รอลงนาม', 'permission' => 'saraban.document.sign'],
        ['code' => 'signed', 'name' => 'ลงนามแล้ว', 'permission' => null],
    ];

    /**
     * บันทึกสายงานสารบรรณเป็น definition + version สถานะ draft
     *
     * idempotent — เรียกซ้ำได้ ไม่สร้างซ้ำ
     */
    public function publishDraft(): WorkflowVersion
    {
        $definition = WorkflowDefinition::firstOrCreate(
            ['code' => self::DEFINITION_CODE],
            [
                'module' => 'saraban',
                'name' => 'สายงานหนังสือราชการ',
                'subject_type' => (new Document)->getMorphClass(),
                'description' => 'บันทึกสายงานที่ DocumentWorkflowService ใช้อยู่ (LEGACY-05 · 05-5) — ยังไม่เปิดใช้',
                'is_active' => false,
            ],
        );

        $existing = $definition->versions()->where('status', WorkflowVersion::STATUS_DRAFT)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($definition) {
            $version = WorkflowVersion::create([
                'workflow_definition_id' => $definition->getKey(),
                'version' => (int) $definition->versions()->max('version') + 1,
                // draft = ยังไม่รับ instance ใด ๆ · activeVersion() จะไม่คืนตัวนี้
                'status' => WorkflowVersion::STATUS_DRAFT,
                'effective_from' => now()->toDateString(),
                'notes' => 'สะท้อนสายงานเดิมของ DocumentWorkflowService — ต้องมี decision เรื่องสิทธิ์อนุมัติ/ลงนามก่อนเปิดใช้',
            ]);

            $steps = [];

            foreach (self::STEPS as $index => $definitionStep) {
                $steps[$definitionStep['code']] = WorkflowStep::create([
                    'workflow_version_id' => $version->getKey(),
                    'code' => $definitionStep['code'],
                    'name' => $definitionStep['name'],
                    'step_order' => $index + 1,
                    'type' => WorkflowStep::TYPE_APPROVAL,
                    'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
                    'assignee_permission' => $definitionStep['permission'],
                    'is_final' => $definitionStep['code'] === 'signed',
                ]);
            }

            $edges = [
                ['draft', 'pending_review', WorkflowTransition::APPROVE, 'ส่งตรวจสอบ', null],
                ['pending_review', 'pending_approval', WorkflowTransition::APPROVE, 'ผ่านการตรวจสอบ', null],
                ['pending_review', 'draft', WorkflowTransition::REJECT, 'ตีกลับให้แก้ไข', null],
                ['pending_approval', 'approved_pending_signature', WorkflowTransition::APPROVE, 'อนุมัติ', null],
                ['pending_approval', 'draft', WorkflowTransition::REJECT, 'ตีกลับให้แก้ไข', null],
                ['approved_pending_signature', 'signed', WorkflowTransition::APPROVE, 'ลงนาม', WorkflowInstance::STATUS_COMPLETED],
            ];

            foreach ($edges as $sortOrder => [$from, $to, $action, $name, $result]) {
                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $steps[$from]->getKey(),
                    'to_step_id' => $steps[$to]->getKey(),
                    'action' => $action,
                    'name' => $name,
                    'result' => $result,
                    'requires_comment' => $action === WorkflowTransition::REJECT,
                    'sort_order' => $sortOrder,
                ]);
            }

            return $version;
        }, 5);
    }
}
