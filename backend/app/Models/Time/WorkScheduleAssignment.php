<?php

namespace App\Models\Time;

use App\Models\Concerns\HasPublicUuid;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * การมอบหมายตารางเวลาแบบ effective-dated
 *
 * `user_id` และ `org_unit_id` ว่างทั้งคู่ = มอบหมายระดับองค์กร
 */
#[Fillable([
    'work_schedule_id', 'user_id', 'org_unit_id',
    'effective_from', 'effective_to', 'note', 'created_by',
])]
class WorkScheduleAssignment extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function schedule()
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * เฉพาะการมอบหมายที่มีผลอยู่ ณ วันที่ระบุ
     *
     * ต้องตรวจทุกครั้ง มิฉะนั้นการแก้ตารางเวลาวันนี้จะเปลี่ยนประวัติการมาสายย้อนหลัง
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($inner) => $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
