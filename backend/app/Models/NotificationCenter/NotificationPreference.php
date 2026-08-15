<?php

namespace App\Models\NotificationCenter;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'notification_type_id', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function type()
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }
}
