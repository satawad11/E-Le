<?php

namespace App\Models\Saraban;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'saraban_audit_logs';

    protected $fillable = [
        'user_id',
        'document_id',
        'action',
        'ip_address',
        'user_agent',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::created(fn (AuditLog $log) => app(AuditLogger::class)->log('saraban.'.$log->action, 'saraban_document', $log->document_id, null, $log->payload));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
