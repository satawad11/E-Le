<?php

namespace App\Models\NotificationCenter;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['notification_type_id', 'channel', 'version', 'locale', 'subject', 'body_template', 'effective_from', 'effective_to', 'is_active'])]
class NotificationTemplate extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];
    }
}
