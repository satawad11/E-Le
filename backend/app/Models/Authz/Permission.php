<?php

namespace App\Models\Authz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission `{module}.{feature}.{action}` (CORE-04) — มาจาก manifest ของโมดูลเท่านั้น (PROJECT_RULES ข้อ 5)
 * ไม่ลบ: permission ที่หายจาก manifest ถูก mark `retired_at`
 */
class Permission extends Model
{
    public const SCOPES = ['own', 'unit', 'subtree', 'all', 'assigned'];

    public const RISKS = ['low', 'medium', 'high'];

    protected $fillable = ['name', 'module_feature_id', 'action', 'label', 'allowed_scopes', 'requires', 'risk', 'rules', 'retired_at'];

    protected $casts = ['allowed_scopes' => 'array', 'requires' => 'array', 'rules' => 'array', 'retired_at' => 'datetime'];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(ModuleFeature::class, 'module_feature_id');
    }
}
