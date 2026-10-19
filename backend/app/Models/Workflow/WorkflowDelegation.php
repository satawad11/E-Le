<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * การมอบอำนาจระดับ workflow
 *
 * `user_delegations` เดิมยังเป็น source of truth ของการมอบอำนาจใบลา
 * แถวในตารางนี้ที่มี `legacy_delegation_id` คือภาพสะท้อนของแถวนั้น ไม่ใช่ข้อมูลคู่ขนาน
 */
#[Fillable([
    'delegator_id', 'delegate_id', 'workflow_definition_id',
    'delegation_type', 'effective_from', 'effective_to', 'is_active', 'reason',
    'legacy_delegation_id', 'created_by',
])]
class WorkflowDelegation extends Model
{
    use HasPublicUuid;

    public const TYPE_MANAGER_DELEGATE = 'MANAGER_DELEGATE';

    public const TYPE_ACTING = 'ACTING';

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function delegator()
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate()
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /**
     * เฉพาะการมอบอำนาจที่มีผลจริง ณ วันที่ระบุ
     *
     * ต้องตรวจ effective date ทุกครั้ง — ผู้ที่พ้นช่วงรับมอบแล้วต้องอนุมัติไม่ได้
     */
    public function scopeEffectiveOn(Builder $query, ?string $on = null): Builder
    {
        $date = $on ?? now()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($inner) => $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
