<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * เจ้าหน้าที่ประจำงานตามคำสั่ง (ACCESS-GOV-01 · C1) — ไม่ใช่หัวหน้า จึงไม่เป็นผู้อนุมัติใบลา
 * ขยายขอบเขตข้อมูลแบบ UNIT/DESCENDANTS ไปยังหน่วยที่ประจำ ปิดด้วย effective_to ไม่ลบ
 */
#[Fillable([
    'org_unit_id', 'user_id', 'order_number', 'order_date',
    'effective_from', 'effective_to', 'note', 'created_by', 'ended_by',
])]
class OrgUnitMember extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'order_date' => 'date'];
    }

    /** @param  Builder<OrgUnitMember>  $query */
    public function scopeInEffect(Builder $query, ?string $on = null): Builder
    {
        $date = $on ?? now()->toDateString();

        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
