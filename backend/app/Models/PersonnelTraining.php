<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelTraining extends Model
{
    use HasPublicUuid;

    protected $fillable = ['personnel_id', 'course', 'organizer', 'hours', 'start_date', 'end_date', 'certificate_document_id', 'verified_by', 'verified_at'];

    protected $casts = ['hours' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date', 'verified_at' => 'datetime'];

    protected $hidden = ['id', 'personnel_id', 'certificate_document_id', 'verified_by'];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(PersonnelDocument::class, 'certificate_document_id');
    }
}
