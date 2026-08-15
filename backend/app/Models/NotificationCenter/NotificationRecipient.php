<?php

namespace App\Models\NotificationCenter;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['notification_event_id', 'user_id', 'read_at'])]
class NotificationRecipient extends Model
{
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function event()
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
