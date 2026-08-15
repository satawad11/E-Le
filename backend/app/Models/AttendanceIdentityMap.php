<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['attendance_source_id', 'external_person_id', 'user_id'])]
class AttendanceIdentityMap extends Model
{
    use HasPublicUuid;

    public function source()
    {
        return $this->belongsTo(AttendanceSource::class, 'attendance_source_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
