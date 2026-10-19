<?php

namespace App\Models\Authz;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ชุดสิทธิ์สำเร็จรูป (CORE-04) — `positional` แนบกับตำแหน่ง/ระดับ · `functional` มอบผ่าน role_grants
 */
class PermissionTemplate extends Model
{
    use HasPublicUuid;

    public const KIND_POSITIONAL = 'positional';

    public const KIND_FUNCTIONAL = 'functional';

    protected $fillable = ['module_id', 'code', 'name', 'kind', 'description', 'attributes_schema', 'retired_at'];

    protected $casts = ['attributes_schema' => 'array', 'retired_at' => 'datetime'];

    protected $hidden = ['id', 'module_id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(TemplatePermission::class, 'template_id');
    }
}
