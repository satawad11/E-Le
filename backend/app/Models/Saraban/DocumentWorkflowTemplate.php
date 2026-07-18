<?php

namespace App\Models\Saraban;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentWorkflowTemplate extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_workflow_templates';

    protected $fillable = [
        'name',
        'description',
        'steps_config',
        'is_active',
    ];

    protected $casts = [
        'steps_config' => 'array',
        'is_active' => 'boolean',
    ];
}
