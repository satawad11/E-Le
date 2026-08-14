<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'module', 'name', 'subject_type', 'description', 'is_active'])]
class WorkflowDefinition extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions()
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /**
     * เวอร์ชันที่มีผลบังคับใช้ ณ วันที่ระบุ
     *
     * ใช้ตอน "เริ่ม" instance ใหม่เท่านั้น — instance ที่รันอยู่แล้ว pin version
     * ของตัวเองไว้ จึงไม่ถูกกระทบเมื่อมีเวอร์ชันใหม่
     */
    public function activeVersion(?string $on = null): ?WorkflowVersion
    {
        $date = $on ?? now()->toDateString();

        return $this->versions()
            ->where('status', WorkflowVersion::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('version')
            ->first();
    }
}
