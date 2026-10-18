<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'workflow_version_id', 'code', 'name', 'step_order', 'type',
    'assignee_type', 'assignee_user_id', 'assignee_permission',
    'completion_policy', 'conditions', 'sla_hours', 'on_timeout', 'escalate_to_step_id', 'is_final',
])]
class WorkflowStep extends Model
{
    use HasPublicUuid;

    public const TYPE_APPROVAL = 'approval';

    public const ASSIGNEE_ROLE = 'role';

    public const ASSIGNEE_USER = 'user';

    public const ASSIGNEE_PERMISSION = 'permission';

    /** หัวหน้าหน่วยงานของผู้ยื่น — ใช้ `org_unit_managers` ที่ LEGACY-04 สร้างไว้ */
    public const ASSIGNEE_ORG_MANAGER = 'org_manager';

    public const ASSIGNEE_INITIATOR = 'initiator';

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_final' => 'boolean',
        ];
    }

    public function version()
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function assigneeUser()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function outgoingTransitions()
    {
        return $this->hasMany(WorkflowTransition::class, 'from_step_id')->orderBy('sort_order');
    }
}
