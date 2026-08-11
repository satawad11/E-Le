<?php

namespace App\Services\Saraban;

use App\Models\Saraban\AuditLog;
use Illuminate\Http\Request;

class AuditLogService
{
    /**
     * Log a Saraban document action.
     */
    public static function log(?int $documentId, string $action, ?array $payload = null, ?Request $request = null): AuditLog
    {
        $request = $request ?? request();
        $user = auth()->user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'document_id' => $documentId,
            'action' => $action,
            'ip_address' => $request?->ip() ?? '127.0.0.1',
            'user_agent' => substr($request?->userAgent() ?? '', 0, 255),
            'payload' => $payload,
        ]);
    }
}
