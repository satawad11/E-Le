<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelLicense extends Model
{
    use HasPublicUuid;

    protected $fillable = ['personnel_id', 'license_type', 'license_number', 'issued_at', 'expires_at', 'verified_by', 'verified_at'];

    protected $casts = ['issued_at' => 'date', 'expires_at' => 'date', 'verified_at' => 'datetime'];

    protected $hidden = ['id', 'personnel_id', 'verified_by'];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
