<?php

namespace App\Models\Authz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * โมดูลที่ลงทะเบียนจาก manifest (CORE-04) — สร้าง/อัปเดตด้วย `modules:sync` (R-04) เท่านั้น
 */
class Module extends Model
{
    protected $fillable = ['code', 'name', 'version', 'enabled', 'manifest_hash', 'synced_at', 'retired_at'];

    protected $casts = ['enabled' => 'boolean', 'synced_at' => 'datetime', 'retired_at' => 'datetime'];

    public function features(): HasMany
    {
        return $this->hasMany(ModuleFeature::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(PermissionTemplate::class);
    }
}
