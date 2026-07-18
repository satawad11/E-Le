<?php

namespace App\Models\Saraban;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentIncoming extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_incoming';
    
    protected $fillable = [
        'document_id',
        'receive_date',
        'from_agency',
        'receiver_id',
    ];

    protected $casts = [
        'receive_date' => 'date',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
