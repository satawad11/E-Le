<?php

namespace App\Models\NotificationCenter;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'module', 'name', 'description', 'default_channels', 'is_mandatory', 'is_active'])]
class NotificationType extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['default_channels' => 'array', 'is_mandatory' => 'boolean', 'is_active' => 'boolean'];
    }
}
