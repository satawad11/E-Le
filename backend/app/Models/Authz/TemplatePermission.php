<?php

namespace App\Models\Authz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * คู่ (permission, scope) ในชุดสิทธิ์ (CORE-04) · `effect = deny` + scope = เพดานที่ยังอนุญาต (null = ปฏิเสธทั้งหมด)
 */
class TemplatePermission extends Model
{
    protected $fillable = ['template_id', 'permission_id', 'effect', 'scope'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class, 'template_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
