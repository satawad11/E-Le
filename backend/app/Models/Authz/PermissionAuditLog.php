<?php

namespace App\Models\Authz;

use App\Models\Concerns\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บันทึกการเปลี่ยนสิทธิ์ทุกชนิด (template / grant / override / position_templates) — เพิ่มได้อย่างเดียว
 */
class PermissionAuditLog extends Model
{
    use AppendOnly;

    protected $fillable = ['actor_user_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'change_request_id', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
