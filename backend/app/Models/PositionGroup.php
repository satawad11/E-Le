<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * กลุ่มตำแหน่งสำหรับจัดกลุ่มรายงาน (CORE-01) — ไม่ให้สิทธิ์ใด ๆ
 */
class PositionGroup extends Model
{
    use HasPublicUuid;

    protected $fillable = ['code', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
