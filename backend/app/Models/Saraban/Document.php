<?php

namespace App\Models\Saraban;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'saraban_documents';
    
    protected $fillable = [
        'document_number',
        'title',
        'type_id',
        'priority',
        'secrecy',
        'status',
        'created_by',
        'department_id',
        'document_date',
        'to',
        'reference',
        'attachments_note',
        'remark',
        'allow_download',
        'incoming_document_number',
        'incoming_date',
        'creation_mode',
        'content',
        'template_type',
    ];

    protected $casts = [
        // เนื้อหาหนังสือถือเป็นข้อมูลอ่อนไหว (CLAUDE.md กฎข้อ 7) — เข้ารหัสที่ชั้น model
        'content' => \App\Casts\TolerantEncrypted::class,
        'allow_download' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function incoming(): HasOne
    {
        return $this->hasOne(DocumentIncoming::class, 'document_id');
    }

    public function outgoing(): HasOne
    {
        return $this->hasOne(DocumentOutgoing::class, 'document_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class, 'document_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'document_id');
    }

    public function receivers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saraban_document_receivers', 'document_id', 'user_id')
                    ->withPivot('read_at')
                    ->withTimestamps();
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(DocumentUserState::class, 'document_id');
    }

    public function getStateForUser($userId)
    {
        return $this->userStates()->where('user_id', $userId)->first();
    }

    public function workflowSteps(): HasMany
    {
        return $this->hasMany(DocumentWorkflowStep::class, 'document_id')->orderBy('step_order');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'document_id');
    }

    public function circulars(): HasMany
    {
        return $this->hasMany(DocumentCircular::class, 'document_id');
    }
}
