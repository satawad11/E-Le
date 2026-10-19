<?php

namespace App\Services\Access;

use App\Models\Authz\PermissionAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * บันทึก `permission_audit_logs` (append-only) ทุกการเปลี่ยน grant / override / คำขอเปลี่ยนสิทธิ์ / การทบทวนสิทธิ์
 */
class PermissionAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(string $action, Model $subject, ?array $before, ?array $after, ?int $actorId, ?int $changeRequestId = null): PermissionAuditLog
    {
        $request = app()->bound('request') ? request() : null;

        return PermissionAuditLog::create([
            'actor_user_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'change_request_id' => $changeRequestId,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
