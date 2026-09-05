<?php

namespace App\Models\NotificationCenter;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'notification_recipient_id', 'notification_template_id', 'channel', 'status',
    'attempt_count', 'max_attempts', 'next_attempt_at', 'last_attempt_at',
    'sent_at', 'dead_lettered_at', 'provider_message_id', 'last_error',
])]
class NotificationDelivery extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer', 'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime',
            // INT-14 — เวลาที่ข้อความเข้า dead-letter (SoT §16)
            'dead_lettered_at' => 'datetime',
        ];
    }

    public function recipient()
    {
        return $this->belongsTo(NotificationRecipient::class, 'notification_recipient_id');
    }

    public function template()
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}
