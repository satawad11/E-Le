<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveActionLog;
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

    /*
     * CLEANUP-02 (D6) — ประวัติที่ผู้ใช้เห็นต้องครบทุกแหล่ง
     *
     * โมดูลลาบันทึกประวัติสองที่: `leave_audit_logs` (การตัดสินสำคัญ) และ
     * `leave_action_logs` (การกระทำของผู้ใช้) ทั้งคู่ส่งต่อเข้าทะเบียนกลางให้เอง
     * แต่หน้าประวัติเดิมอ่านเฉพาะตารางแรก ผู้ใช้จึงไม่เห็นการยื่นใบลาและการขอยกเลิกเลย
     */
    public function test_the_leave_history_endpoint_shows_actions_from_every_source(): void
    {
        $department = Department::create(['name' => 'ประวัติ']);
        $user = User::factory()->create(['department_id' => $department->id]);
        $type = LeaveType::create(['name' => 'ลาประวัติ', 'max_days' => 1]);
        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id,
            'start_date' => today(), 'end_date' => today(), 'total_days' => 1, 'reason' => 'test',
        ]);

        LeaveAuditLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'approval_approved']);
        LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Cancellation Requested']);

        $actions = collect(
            $this->actingAs($user)->getJson("/api/leaves/{$leave->id}/audit")->assertOk()->json('data')
        )->pluck('action')->all();

        $this->assertContains('leave.approval_approved', $actions);
        $this->assertContains('leave.action.Cancellation Requested', $actions, 'ประวัติต้องรวมการกระทำจากอีกตารางหนึ่งด้วย');
    }

    public function test_the_leave_history_is_not_readable_by_an_unrelated_person(): void
    {
        $department = Department::create(['name' => 'ประวัติปิด']);
        $owner = User::factory()->create(['department_id' => $department->id]);
        $type = LeaveType::create(['name' => 'ลาปิด', 'max_days' => 1]);
        $leave = Leave::create([
            'user_id' => $owner->id, 'leave_type_id' => $type->id,
            'start_date' => today(), 'end_date' => today(), 'total_days' => 1, 'reason' => 'test',
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/leaves/{$leave->id}/audit")
            ->assertForbidden();
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
