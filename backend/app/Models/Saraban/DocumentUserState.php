<?php

namespace App\Models\Saraban;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentUserState extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_user_states';

    protected $fillable = [
        'document_id',
        'user_id',
        'is_read',
        'is_pinned',
        'is_hidden',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'is_pinned' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
