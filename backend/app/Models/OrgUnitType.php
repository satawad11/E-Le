<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ประเภทหน่วยงาน (CORE-01) — ระดับ L1–L4 อ่านจาก `level` ไม่ใช่ความลึกของต้นไม้ (ADR-R-009)
 */
class OrgUnitType extends Model
{
    protected $fillable = ['code', 'name', 'level', 'head_title', 'is_active'];

    protected $casts = ['level' => 'integer', 'is_active' => 'boolean'];

    public function orgUnits(): HasMany
    {
        return $this->hasMany(OrgUnit::class);
    }
}
