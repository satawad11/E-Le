<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workflow_definition_id', 'version', 'status', 'effective_from', 'effective_to', 'notes', 'published_by', 'published_at'])]
class WorkflowVersion extends Model
{
    use HasPublicUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function steps()
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('step_order');
    }

    public function transitions()
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    public function instances()
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    /**
     * มี instance ที่ยังรันอยู่บนเวอร์ชันนี้หรือไม่
     *
     * ใช้กันไม่ให้แก้เวอร์ชันที่กำลังถูกใช้งาน — ต้องออกเวอร์ชันใหม่แทน
     * ตามกฎของ `docs/07-WORKFLOW-ENGINE.md`
     */
    public function hasRunningInstances(): bool
    {
        return $this->instances()->where('status', WorkflowInstance::STATUS_RUNNING)->exists();
    }
}
