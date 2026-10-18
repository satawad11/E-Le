<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'workflow_instance_id', 'workflow_step_id', 'status', 'sequence',
    'assignee_user_id', 'completed_by', 'effective_actor_id',
    'delegated_by_id', 'due_at', 'completed_at', 'comment',
])]
class WorkflowTask extends Model
{
    use HasPublicUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function instance()
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function step()
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    /** ผู้กดจริง — อาจเป็นผู้รับมอบอำนาจ */
    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** ผู้ที่ควรเป็นเจ้าของงานตามสายอนุมัติ */
    public function effectiveActor()
    {
        return $this->belongsTo(User::class, 'effective_actor_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
