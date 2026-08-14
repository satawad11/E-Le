<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGACY-05 · 05-3 — หลักฐานของ shadow mode
 *
 * ทุกครั้งที่ตรรกะเดิมตัดสินอะไร engine ใหม่จะคำนวณคู่ขนานแล้วบันทึกไว้ที่นี่
 * **ผลของตรรกะเดิมยังเป็น source of truth** engine ใหม่ไม่มีผลต่อการตัดสินใด ๆ
 *
 * กฎข้อ 9 ของเฟสนี้: ถ้ามี mismatch แม้แต่รายการเดียว ห้าม cutover
 * ตารางนี้คือสิ่งที่ใช้ตอบคำถามนั้น
 */
#[Fillable([
    'workflow_instance_id', 'subject_type', 'subject_id', 'checkpoint',
    'legacy_result', 'engine_result', 'matched', 'mismatch_details', 'actor_id', 'correlation_id',
])]
class WorkflowShadowComparison extends Model
{
    use HasPublicUuid;

    public const UPDATED_AT = null;

    public const CHECKPOINT_CURRENT_STEP = 'current_step';

    public const CHECKPOINT_NEXT_APPROVER = 'next_approver';

    public const CHECKPOINT_TRANSITIONS = 'available_transitions';

    public const CHECKPOINT_APPROVE = 'approve';

    public const CHECKPOINT_REJECT = 'reject';

    protected function casts(): array
    {
        return [
            'legacy_result' => 'array',
            'engine_result' => 'array',
            'mismatch_details' => 'array',
            'matched' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function instance()
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }
}
