<?php

namespace App\Models\Authz;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การมอบชุดสิทธิ์เชิงหน้าที่ (functional) ให้บัญชี (CORE-04)
 *
 * บังคับเลขคำสั่งและวันหมดอายุ (PROJECT_RULES ข้อ 9) · `attributes` เก็บ duty attributes (ABAC)
 * เช่น personnel_type_ids, org_unit_ids, amount_limit ตามที่ template ประกาศใน attributes_schema
 */
class RoleGrant extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'user_id', 'template_id', 'attributes', 'order_number', 'granted_by', 'approved_by',
        'effective_from', 'expires_at', 'ended_at', 'ended_by', 'end_reason',
    ];

    protected $casts = ['attributes' => 'array', 'effective_from' => 'date', 'expires_at' => 'date', 'ended_at' => 'datetime'];

    protected $hidden = ['id', 'user_id', 'template_id', 'granted_by', 'approved_by', 'ended_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class, 'template_id');
    }
}
