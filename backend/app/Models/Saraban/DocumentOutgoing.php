<?php

namespace App\Models\Saraban;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentOutgoing extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_outgoing';

    protected $fillable = [
        'document_id',
        'dispatch_date',
        'to_agency',
        'dispatch_method',
    ];

    protected $casts = [
        'dispatch_date' => 'date',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
