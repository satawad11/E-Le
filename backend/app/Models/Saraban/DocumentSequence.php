<?php

namespace App\Models\Saraban;

use App\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_sequences';

    protected $fillable = ['year_be', 'org_unit_id', 'document_type_id', 'running_number'];

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
