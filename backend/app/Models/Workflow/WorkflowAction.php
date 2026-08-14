<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * บันทึกการกระทำบน workflow — append-oriented
 *
 * ตาม PROJECT_RULES ข้อ 8 และข้อห้าม "overwrite ประวัติ" — ห้าม update/delete
 * แถวในตารางนี้ ตัวโมเดลจึงปิด `UPDATED_AT` และบล็อกการแก้ไขไว้ชั้นหนึ่ง
 */
#[Fillable([
    'workflow_instance_id', 'workflow_task_id', 'action', 'actor_id', 'effective_actor_id',
    'delegated_by_id', 'from_step_id', 'to_step_id', 'comment', 'correlation_id',
    'ip_address', 'user_agent', 'context',
])]
class WorkflowAction extends Model
{
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('workflow_actions เป็น append-only ห้ามแก้ไขย้อนหลัง'));
        static::deleting(fn () => throw new \RuntimeException('workflow_actions เป็น append-only ห้ามลบ'));
    }

    public function instance()
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function effectiveActor()
    {
        return $this->belongsTo(User::class, 'effective_actor_id');
    }
}
