<?php

namespace App\Models\Saraban;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentWorkflowStep extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_workflow_steps';

    protected $fillable = [
        'document_id',
        'user_id',
        'action',
        'comment',
        'action_at',
        'step_order',
        'ip_address',
    ];

    protected $casts = [
        'action_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
