<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ข้อมูล MFA (CORE-03) — secret และรหัสสำรองเข้ารหัสที่ฐานข้อมูล ไม่ส่งออก API
 */
class MfaCredential extends Model
{
    protected $fillable = ['user_id', 'type', 'secret', 'confirmed_at', 'recovery_codes', 'last_used_at'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
