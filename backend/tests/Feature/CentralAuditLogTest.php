<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_user_audit_never_stores_phone_or_messaging_ids_in_plaintext(): void
    {
        $department = Department::create(['name' => 'Audit PDPA']);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'phone' => '0812345678',
            'telegram_chat_id' => 'tg-998877',
            'line_user_id' => 'U-line-445566',
        ]);
        $user->update(['phone' => '0899999999', 'name' => 'เปลี่ยนชื่อ']);
        // users ถูกอ้างโดยประวัติบุคลากร (FK restrict) จึงลบจริงไม่ได้ — ยิง event deleted เพื่อทดสอบ audit hook โดยตรง
        User::getEventDispatcher()->dispatch('eloquent.deleted: '.User::class, $user->fresh());

        $rows = AuditLog::where('resource_type', 'user')->where('resource_id', (string) $user->id)->get();
        $this->assertEqualsCanonicalizing(['user.created', 'user.updated', 'user.deleted'], $rows->pluck('action')->all());

        // ตรวจค่าดิบในคอลัมน์ ไม่ผ่าน cast
        $raw = DB::table('audit_logs')->where('resource_type', 'user')->where('resource_id', (string) $user->id)
            ->get(['before', 'after'])->map(fn ($row) => $row->before.$row->after)->implode('');
        foreach (['0812345678', '0899999999', 'tg-998877', 'U-line-445566'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        $updated = $rows->firstWhere('action', 'user.updated');
        $this->assertSame('[REDACTED]', $updated->before['phone']);
        $this->assertSame('[REDACTED]', $updated->after['phone']);
        $this->assertSame(['phone'], $updated->after['redacted_changed']);
        $this->assertSame('เปลี่ยนชื่อ', $updated->after['name']);
        $this->assertSame('[REDACTED]', $rows->firstWhere('action', 'user.created')->after['telegram_chat_id']);
    }
}
