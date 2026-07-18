<?php

namespace App\Models\Saraban;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_types';
    protected $fillable = ['name', 'code', 'color'];

    public function sequences(): HasMany
    {
        return $this->hasMany(DocumentSequence::class, 'document_type_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'type_id');
    }
}
