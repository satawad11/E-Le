<?php

namespace App\Models\NotificationCenter;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['notification_type_id', 'actor_id', 'related_type', 'related_id', 'payload', 'status', 'occurred_at'])]
class NotificationEvent extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function type()
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function recipients()
    {
        return $this->hasMany(NotificationRecipient::class);
    }
}
