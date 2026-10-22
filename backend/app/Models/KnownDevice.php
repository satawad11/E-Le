<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * อุปกรณ์ที่ผู้ใช้เคยเข้าใช้ (CORE-03 · R-05) — เก็บเฉพาะ hash ของรหัสอุปกรณ์ ไม่เก็บรหัสจริง
 *
 * อุปกรณ์ที่ไม่รู้จักหรือพ้น `trusted_until` แล้ว = อุปกรณ์ใหม่ → อีเมลแจ้งเตือนเจ้าของบัญชี
 */
class KnownDevice extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'device_hash', 'label', 'ip', 'first_seen_at', 'last_seen_at', 'trusted_until'];

    protected $hidden = ['id', 'user_id', 'device_hash'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'trusted_until' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
