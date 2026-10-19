<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ตำแหน่ง (CORE-01)
 *
 * `kind = line` ตำแหน่งงาน (ครู เจ้าหน้าที่ ฯลฯ) · `kind = management` ตำแหน่งบริหาร ผูกกับประเภทหน่วย
 * ได้ประเภทละหนึ่งตำแหน่ง สิทธิ์ของตำแหน่งแนบผ่าน `position_templates` (CORE-04) ไม่อยู่ที่ตารางนี้
 */
class Position extends Model
{
    use HasPublicUuid;

    public const KIND_LINE = 'line';

    public const KIND_MANAGEMENT = 'management';

    protected $fillable = ['code', 'name', 'kind', 'position_group_id', 'org_unit_type_id', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected $hidden = ['id', 'position_group_id', 'org_unit_type_id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PositionGroup::class, 'position_group_id');
    }

    public function orgUnitType(): BelongsTo
    {
        return $this->belongsTo(OrgUnitType::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
