<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'workflow_version_id', 'subject_type', 'subject_id', 'status', 'current_step_id',
    'initiated_by', 'is_shadow', 'correlation_id', 'meta', 'started_at', 'completed_at',
])]
class WorkflowInstance extends Model
{
    use HasPublicUuid;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_shadow' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(null, 'subject_type', 'subject_id');
    }

    /** เวอร์ชันที่ pin ไว้ตอนเริ่ม — ไม่เปลี่ยนตลอดอายุของ instance */
    public function version()
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function currentStep()
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    public function tasks()
    {
        return $this->hasMany(WorkflowTask::class)->orderBy('sequence');
    }

    public function actions()
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }
}
