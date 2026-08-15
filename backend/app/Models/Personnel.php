<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Personnel extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'personnel';

    protected $fillable = [
        'uuid',
        'user_id',
        'personnel_code',
        'title',
        'first_name',
        'last_name',
        'status',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignments()
    {
        return $this->hasMany(PersonnelAssignment::class, 'user_id', 'user_id');
    }

    public function currentAssignment()
    {
        return $this->hasOne(PersonnelAssignment::class, 'user_id', 'user_id')
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', today());
            })
            ->whereDate('effective_from', '<=', today())
            ->latest('effective_from');
    }

    public function getFullNameAttribute(): string
    {
        $fullName = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        if ($this->title && ! str_starts_with($fullName, $this->title)) {
            return $this->title.$fullName;
        }

        return $fullName;
    }
}
