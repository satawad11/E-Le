<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplateVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
