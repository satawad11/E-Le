<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บันทึกการเข้าสู่ระบบ (CORE-03) — เพิ่มได้อย่างเดียว
 */
class LoginEvent extends Model
{
    use AppendOnly;

    public const RESULTS = ['success', 'bad_password', 'locked', 'mfa_failed', 'new_device'];

    protected $fillable = ['user_id', 'username_attempted', 'result', 'ip', 'user_agent', 'created_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
