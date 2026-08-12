<?php

namespace App\Services\Saraban;

use App\Models\Saraban\AuditLog;
use App\Models\Saraban\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Central writer for saraban_audit_logs.
 *
 * CLAUDE.md rule 8 requires every endpoint that edits, deletes, signs or
 * downloads a document to leave an audit trail with user_id, action, ip and
 * timestamp. Route this through here rather than touching AuditLog directly.
 */
class AuditLogService
{
    /**
     * @param  string  $action  e.g. 'document.updated', 'document.signed'
     * @param  Document|int|null  $document  the affected document (or its id)
     * @param  array  $payload  extra context; keep it small and non-sensitive
     */
    public function log(string $action, Document|int|null $document = null, array $payload = [], ?Request $request = null): AuditLog
    {
        $request ??= request();

        $documentId = $document instanceof Document ? $document->id : $document;

        return AuditLog::create([
            'user_id' => Auth::id() ?? $request?->user()?->id,
            'document_id' => $documentId,
            'action' => $action,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'payload' => $payload ?: null,
        ]);
    }
}
