<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * การเป็นหัวหน้า/รักษาการ/รับมอบอำนาจของหน่วยงาน ตามคำสั่งแต่งตั้ง (ACCESS-GOV-01)
 *
 * แถวที่ผ่านมาแล้วไม่ถูกลบหรือแก้ย้อนหลัง — ปิดวาระด้วย effective_to เท่านั้น
 * ApproverResolverService และ OrgScopeResolver อ่านตารางนี้ตามวันมีผล
 */
#[Fillable([
    'org_unit_id', 'user_id', 'assignment_type', 'scope', 'order_number', 'order_date',
    'effective_from', 'effective_to', 'note', 'created_by', 'ended_by',
])]
class OrgUnitManager extends Model
{
    use HasPublicUuid;

    public const TYPE_MANAGER = 'MANAGER';

    public const TYPE_ACTING = 'ACTING';

    public const TYPE_DELEGATE = 'DELEGATE';

    public const TYPES = [self::TYPE_MANAGER, self::TYPE_ACTING, self::TYPE_DELEGATE];

    public const SCOPES = ['UNIT', 'DESCENDANTS'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'order_date' => 'date'];
    }

    /** @param  Builder<OrgUnitManager>  $query */
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
