<?php

namespace App\Services\Workflow;

use App\Models\AttendanceCorrection;
use App\Models\Workflow\WorkflowDefinition;
use App\Models\Workflow\WorkflowInstance;
use App\Models\Workflow\WorkflowStep;
use App\Models\Workflow\WorkflowTransition;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class AttendanceCorrectionWorkflowProvisioner
{
    public const DEFINITION_CODE = 'attendance.correction';

    public function __construct(private readonly WorkflowEngine $engine) {}

    public function start(AttendanceCorrection $correction): WorkflowInstance
    {
        $definition = $this->definition();
        $this->ensureVersion($definition);

        return $this->engine->start($definition, $correction, $correction->requested_by, false, [
            'attendance_id' => $correction->attendance_id,
            'user_id' => $correction->user_id,
        ]);
    }

    private function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::firstOrCreate(
            ['code' => self::DEFINITION_CODE],
            [
                'module' => 'attendance',
                'name' => 'การอนุมัติแก้ไขข้อมูลลงเวลา',
                'subject_type' => (new AttendanceCorrection)->getMorphClass(),
                'description' => 'คำขอแก้ไขผลลงเวลาต้องผ่านผู้มีสิทธิ์ดูข้อมูลลงเวลาตาม scope',
                'is_active' => true,
            ],
        );
    }

    private function ensureVersion(WorkflowDefinition $definition): WorkflowVersion
    {
        if ($active = $definition->activeVersion()) {
            return $active;
        }

        return DB::transaction(function () use ($definition) {
            $version = WorkflowVersion::create([
                'workflow_definition_id' => $definition->getKey(),
                'version' => ((int) $definition->versions()->max('version')) + 1,
                'status' => WorkflowVersion::STATUS_ACTIVE,
                'effective_from' => now()->toDateString(),
                'notes' => 'LEGACY-08 attendance correction workflow',
                'published_at' => now(),
            ]);

            $step = WorkflowStep::create([
                'workflow_version_id' => $version->getKey(),
                'code' => 'attendance_reviewer',
                'name' => 'ตรวจสอบการแก้ไขข้อมูลลงเวลา',
                'step_order' => 1,
                'type' => WorkflowStep::TYPE_APPROVAL,
                'assignee_type' => WorkflowStep::ASSIGNEE_PERMISSION,
                'assignee_permission' => 'attendance.view_all',
                'completion_policy' => 'any',
                'is_final' => true,
            ]);

            foreach ([
                [WorkflowTransition::APPROVE, 'อนุมัติ', WorkflowInstance::STATUS_COMPLETED, false],
                [WorkflowTransition::REJECT, 'ไม่อนุมัติ', WorkflowInstance::STATUS_REJECTED, true],
            ] as [$action, $name, $result, $requiresComment]) {
                WorkflowTransition::create([
                    'workflow_version_id' => $version->getKey(),
                    'from_step_id' => $step->getKey(),
                    'action' => $action,
                    'name' => $name,
                    'result' => $result,
                    'requires_comment' => $requiresComment,
                ]);
            }

            return $version;
        }, 5);
    }
}
