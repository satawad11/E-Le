<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'type', 'timezone', 'config', 'health_state', 'cursor', 'last_seen_at', 'is_active'])]
class AttendanceSource extends Model
{
    use HasPublicUuid;

    protected function casts(): array
    {
        return ['config' => 'array', 'last_seen_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function devices()
    {
        return $this->hasMany(AttendanceDevice::class);
    }

    public function rawEvents()
    {
        return $this->hasMany(AttendanceRawEvent::class);
    }
}
