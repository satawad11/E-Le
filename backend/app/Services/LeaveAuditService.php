<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveAuditLog;

class LeaveAuditService
{
    public function log(?Leave $leave, string $action, ?array $before = null, ?array $after = null): void
    {
        $request = request();
        LeaveAuditLog::create(['leave_id' => $leave?->id, 'user_id' => $request->user()?->id, 'action' => $action, 'before' => $before, 'after' => $after, 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 1000)]);
    }
}
