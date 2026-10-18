<?php

namespace App\Models\Authz;

use App\Models\Concerns\HasPublicUuid;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สิทธิ์รายบุคคลแบบ allow/deny (CORE-04) — deny ชนะเสมอ · allow ต้องมีวันหมดอายุ (ตรวจใน service R-05)
 */
class PermissionOverride extends Model
{
    use HasPublicUuid;

    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    protected $fillable = [
        'user_id', 'permission_id', 'effect', 'scope', 'org_unit_id', 'reason',
        'granted_by', 'approved_by', 'expires_at', 'ended_at',
    ];

    protected $casts = ['expires_at' => 'date', 'ended_at' => 'datetime'];

    protected $hidden = ['id', 'user_id', 'permission_id', 'org_unit_id', 'granted_by', 'approved_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }
}
