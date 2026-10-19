<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelEducation extends Model
{
    use HasPublicUuid;

    protected $table = 'personnel_educations';

    protected $fillable = ['personnel_id', 'level', 'degree', 'major', 'institution', 'graduated_year', 'verified_by', 'verified_at'];

    protected $casts = ['graduated_year' => 'integer', 'verified_at' => 'datetime'];

    protected $hidden = ['id', 'personnel_id', 'verified_by'];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
