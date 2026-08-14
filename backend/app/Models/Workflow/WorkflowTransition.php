<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'workflow_version_id', 'from_step_id', 'to_step_id', 'action', 'name',
    'result', 'conditions', 'sort_order', 'requires_comment',
])]
class WorkflowTransition extends Model
{
    use HasPublicUuid;

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const RETURN = 'return';

    public const CANCEL = 'cancel';

    public const DELEGATE = 'delegate';

    public const ESCALATE = 'escalate';

    public const TIMEOUT = 'timeout';

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'requires_comment' => 'boolean',
        ];
    }

    public function fromStep()
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    public function toStep()
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
