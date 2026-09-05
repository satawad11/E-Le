<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * กุญแจกันทำซ้ำหนึ่งใบ (SEC-GAP-04 · SoT §17)
 *
 * เป็นความสามารถของระบบกลาง ไม่ใช่ของสารบรรณ — โมดูลใดก็ผูก middleware
 * `idempotent` เข้ากับ route ของตนได้ทันที
 */
#[Fillable([
    'idempotency_key', 'user_id', 'method', 'path', 'request_hash',
    'response_status', 'response_body', 'completed_at', 'expires_at',
])]
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
