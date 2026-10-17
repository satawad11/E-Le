<?php

namespace App\Models\Saraban;

use App\Models\User;
use App\Support\Labels\SarabanWorkflowActionLabel;
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

    /** ป้ายภาษาไทยสำหรับแสดงผล — `action` เป็นรหัสภายใน ห้ามแสดงตรง ๆ */
    protected $appends = ['action_label'];

    public function getActionLabelAttribute(): string
    {
        return SarabanWorkflowActionLabel::label($this->action);
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
