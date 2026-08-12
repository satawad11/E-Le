<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, Model|string $resource, int|string|null $resourceId = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?int $actorId = null): AuditLog
    {
        $request = app()->bound('request') ? request() : null;
        $actorId ??= $request?->user()?->id;
        $resourceType = $resource instanceof Model ? $resource->getMorphClass() : $resource;
        $resourceId ??= $resource instanceof Model ? $resource->getKey() : null;

        return AuditLog::create([
            'actor_id' => $actorId,
            'effective_actor_id' => $actorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId === null ? null : (string) $resourceId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'correlation_id' => $request?->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
