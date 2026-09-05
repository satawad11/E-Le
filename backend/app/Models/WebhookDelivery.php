<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * การส่ง webhook หนึ่งครั้ง (SoT §17)
 *
 * ใช้กติกา outbox/retry/dead-letter ชุดเดียวกับศูนย์แจ้งเตือน (INT-14)
 * — ไม่คิดกลไกใหม่สำหรับปัญหาเดิม
 */
#[Fillable([
    'uuid', 'webhook_endpoint_id', 'event', 'payload', 'status',
    'attempt_count', 'max_attempts', 'next_attempt_at', 'last_attempt_at',
    'sent_at', 'dead_lettered_at', 'response_status', 'last_error', 'correlation_id',
])]
class WebhookDelivery extends Model
{
    use HasPublicUuid;

    protected $table = 'webhook_deliveries';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
        ];
    }

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
