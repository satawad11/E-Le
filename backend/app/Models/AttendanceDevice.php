<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['attendance_source_id', 'external_id', 'name', 'location', 'org_unit_id', 'timezone', 'metadata', 'is_active'])]
class AttendanceDevice extends Model
{
    use HasPublicUuid;

    protected $table = 'devices';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_active' => 'boolean'];
    }

    public function source()
    {
        return $this->belongsTo(AttendanceSource::class, 'attendance_source_id');
    }
}
