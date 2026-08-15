<?php

namespace App\Services\Workflow;

use App\Models\Overtime\OvertimeOrder;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class OvertimeOrderWorkflowProvisioner
{
    public const DEFINITION_CODE = 'overtime.order';

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
            $step = WorkflowStep::create([
                'workflow_version_id' => $version->id, 'code' => 'overtime_approval',
                'name' => 'อนุมัติคำสั่งปฏิบัติงานล่วงเวลา', 'step_order' => 1,
                'type' => WorkflowStep::TYPE_APPROVAL,
                'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
                'assignee_permission' => 'overtime.management.review',
                'completion_policy' => 'any', 'is_final' => true,
            ]);

            foreach ([
                [WorkflowTransition::APPROVE, 'อนุมัติ', WorkflowInstance::STATUS_COMPLETED, false],
                [WorkflowTransition::REJECT, 'ไม่อนุมัติ', WorkflowInstance::STATUS_REJECTED, true],
            ] as [$action, $name, $result, $requiresComment]) {
                WorkflowTransition::create([
                    'workflow_version_id' => $version->id, 'from_step_id' => $step->id,
                    'action' => $action, 'name' => $name, 'result' => $result,
                    'requires_comment' => $requiresComment,
                ]);
            }

            return $version;
        }, 5);
    }
}
