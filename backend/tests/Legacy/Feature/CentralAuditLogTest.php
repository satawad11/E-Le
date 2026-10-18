<?php

namespace Tests\Legacy\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_audit_schema_contains_required_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'actor_id', 'effective_actor_id', 'action', 'resource_type', 'resource_id',
            'before', 'after', 'reason', 'ip_address', 'user_agent', 'correlation_id', 'created_at',
        ]));
    }

    public function test_legacy_leave_audit_dual_writes_to_central_log(): void
    {
        $department = Department::create(['name' => 'Audit']);
        $user = User::factory()->create(['department_id' => $department->id]);
        $type = LeaveType::create(['name' => 'ลา Audit', 'max_days' => 1]);
        $leave = Leave::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'start_date' => today(), 'end_date' => today(), 'total_days' => 1, 'reason' => 'test']);

        LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'tested']);

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'leave.tested', 'resource_type' => 'leave', 'resource_id' => (string) $leave->id]);
    }

    public function test_user_and_attendance_mutations_are_audited(): void
    {
        $department = Department::create(['name' => 'Audit mutation']);
        $user = User::factory()->create(['department_id' => $department->id]);
        $attendance = Attendance::create(['user_id' => $user->id, 'date' => today()]);
        $attendance->update(['notes' => 'แก้ไข']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'resource_type' => 'user', 'resource_id' => (string) $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.updated', 'resource_type' => 'attendance', 'resource_id' => (string) $attendance->id]);
        $userAudit = AuditLog::where('action', 'user.created')->where('resource_id', (string) $user->id)->firstOrFail();
        $this->assertArrayNotHasKey('password', $userAudit->after);
    }
}
