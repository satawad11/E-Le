<?php

namespace App\Services\Workflow;

use App\Models\Overtime\OvertimeOrder;
use App\Models\Role;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class OvertimeOrderWorkflowProvisioner
{
    public const DEFINITION_CODE = 'overtime.order';

    /**
     * สายอนุมัติคำสั่ง OT ตามที่เจ้าของระบบกำหนด (2026-08-19)
     *
     *   หัวหน้าบุคลากร        ตั้งคำสั่ง   (สิทธิ์ overtime.operation.execute)
     *   รอง ผอ. ฝ่ายทรัพยากร  อนุญาต      ← ขั้นที่ 1
     *   ผู้อำนวยการ           อนุมัติ      ← ขั้นที่ 2 จบสาย
     *
     * คำสั่งจะมีสถานะ `approved` และลงเวลา OT ได้ ก็ต่อเมื่อผ่านครบทั้งสองขั้น
     */
    private const CHAIN = [
        ['code' => 'overtime_permit', 'name' => 'อนุญาตโดยรองผู้อำนวยการฝ่ายทรัพยากร', 'role' => 'Deputy Director'],
        ['code' => 'overtime_approval', 'name' => 'อนุมัติโดยผู้อำนวยการ', 'role' => 'Director'],
    ];

    public function __construct(private readonly WorkflowEngine $engine) {}

    public function start(OvertimeOrder $order): WorkflowInstance
    {
        $definition = WorkflowDefinition::firstOrCreate(
            ['code' => self::DEFINITION_CODE],
            [
                'module' => 'overtime', 'name' => 'การอนุมัติคำสั่งปฏิบัติงานล่วงเวลา',
                'subject_type' => (new OvertimeOrder)->getMorphClass(),
                'description' => 'อนุมัติเวลา OT ก่อนมอบหมายและบันทึกเวลาปฏิบัติจริง', 'is_active' => true,
            ],
        );
        $this->ensureVersion($definition);

        return $this->engine->start($definition, $order, $order->created_by, false, ['org_unit_id' => $order->org_unit_id]);
    }

    private function ensureVersion(WorkflowDefinition $definition): WorkflowVersion
    {
        if ($active = $definition->activeVersion()) {
            return $active;
        }

        return DB::transaction(function () use ($definition) {
            $version = WorkflowVersion::create([
                'workflow_definition_id' => $definition->id,
                'version' => ((int) $definition->versions()->max('version')) + 1,
                'status' => WorkflowVersion::STATUS_ACTIVE, 'effective_from' => now()->toDateString(),
                'notes' => 'LEGACY-09 overtime order workflow', 'published_at' => now(),
            ]);
            $steps = [];

            foreach (array_values(self::CHAIN) as $index => $level) {
                $roleId = Role::where('name', $level['role'])->value('id');

                $steps[] = WorkflowStep::create([
                    'workflow_version_id' => $version->id,
                    'code' => $level['code'],
                    'name' => $level['name'],
                    'step_order' => $index + 1,
                    'type' => WorkflowStep::TYPE_APPROVAL,
                    /*
                     * ผูกกับ role ตามระเบียบ หากยังไม่มี role นั้นในระบบ
                     * ถอยไปใช้สิทธิ์แทนเพื่อไม่ให้สายอนุมัติค้างโดยไม่มีผู้รับผิดชอบ
                     */
                    'assignee_type' => $roleId === null ? WorkflowStep::ASSIGNEE_PERMISSION : WorkflowStep::ASSIGNEE_ROLE,
                    'assignee_role_id' => $roleId,
                    'assignee_permission' => $roleId === null ? 'overtime.management.review' : null,
                    'completion_policy' => 'any',
                    'is_final' => $index === count(self::CHAIN) - 1,
                ]);
            }

            foreach ($steps as $index => $step) {
                $next = $steps[$index + 1] ?? null;

                // อนุญาต/อนุมัติ → ไปขั้นถัดไป หรือจบสายเมื่อเป็นขั้นสุดท้าย
                WorkflowTransition::create([
                    'workflow_version_id' => $version->id,
                    'from_step_id' => $step->id,
                    'to_step_id' => $next?->id,
                    'action' => WorkflowTransition::APPROVE,
                    'name' => $next === null ? 'อนุมัติ' : 'อนุญาต',
                    'result' => $next === null ? WorkflowInstance::STATUS_COMPLETED : null,
                    'requires_comment' => false,
                    'sort_order' => 0,
                ]);

                // ไม่อนุญาต/ไม่อนุมัติ → จบสายทันทีทุกขั้น และต้องระบุเหตุผล
                WorkflowTransition::create([
                    'workflow_version_id' => $version->id,
                    'from_step_id' => $step->id,
                    'to_step_id' => null,
                    'action' => WorkflowTransition::REJECT,
                    'name' => $next === null ? 'ไม่อนุมัติ' : 'ไม่อนุญาต',
                    'result' => WorkflowInstance::STATUS_REJECTED,
                    'requires_comment' => true,
                    'sort_order' => 1,
                ]);
            }

            return $version;
        }, 5);
    }
}
