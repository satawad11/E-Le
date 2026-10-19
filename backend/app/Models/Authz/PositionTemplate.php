<?php

namespace App\Models\Authz;

use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * แนบชุดสิทธิ์กับตำแหน่ง (`position_id`) หรือกับระดับหน่วย (`org_unit_type_level`) (CORE-04)
 */
class PositionTemplate extends Model
{
    protected $fillable = ['position_id', 'org_unit_type_level', 'template_id'];

    protected $casts = ['org_unit_type_level' => 'integer'];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class, 'template_id');
    }
}
