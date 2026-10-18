<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บันทึกการเปิดดูข้อมูลชั้น sensitive (CORE-02) — เพิ่มได้อย่างเดียว
 */
class SensitiveAccessLog extends Model
{
    use AppendOnly;

    protected $fillable = ['actor_user_id', 'acting_as', 'personnel_id', 'fields', 'reason', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['acting_as' => 'array', 'fields' => 'array'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
