<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveActionLog;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\AuditLogGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REDESIGN-2569 · D-07 — ประวัติการตรวจสอบห้ามแก้ไขหรือลบ (บังคับที่ระดับฐานข้อมูล)
 */
class AuditLogAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function auditRow(?int $actorId = null): int
    {
        return DB::table('audit_logs')->insertGetId([
            'actor_id' => $actorId, 'action' => 'leave.approved', 'resource_type' => 'leave',
            'resource_id' => '1', 'after' => json_encode(['status' => 'Approved']), 'created_at' => now(),
        ]);
    }

    private function assertBlocked(callable $statement): void
    {
        try {
            $statement();
            $this->fail('ฐานข้อมูลต้องปฏิเสธการแก้หรือลบประวัติการตรวจสอบ');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ห้ามแก้ไขหรือลบประวัติการตรวจสอบ', $e->getMessage());
        }
    }

    public function test_audit_log_content_cannot_be_updated(): void
    {
        $id = $this->auditRow();

        $this->assertBlocked(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'leave.rejected']));
        $this->assertBlocked(fn () => DB::table('audit_logs')->where('id', $id)->update(['after' => null]));

        $this->assertSame('leave.approved', DB::table('audit_logs')->where('id', $id)->value('action'));
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $id = $this->auditRow();

        $this->assertBlocked(fn () => DB::table('audit_logs')->where('id', $id)->delete());
        $this->assertTrue(DB::table('audit_logs')->where('id', $id)->exists());
    }

    public function test_other_audit_tables_are_protected_too(): void
    {
        $user = User::factory()->create();
        $leaveAuditId = DB::table('leave_audit_logs')->insertGetId(['user_id' => $user->id, 'action' => 'created', 'created_at' => now()]);
        $settingAuditId = DB::table('setting_audit_logs')->insertGetId(['user_id' => $user->id, 'changes' => json_encode(['school_name' => 'ก']), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertBlocked(fn () => DB::table('leave_audit_logs')->where('id', $leaveAuditId)->update(['action' => 'x']));
        $this->assertBlocked(fn () => DB::table('leave_audit_logs')->where('id', $leaveAuditId)->delete());
        $this->assertBlocked(fn () => DB::table('setting_audit_logs')->where('id', $settingAuditId)->delete());
    }

    public function test_a_referenced_row_can_still_be_set_to_null(): void
    {
        $user = User::factory()->create();
        $id = $this->auditRow($user->id);

        // ผลของ ON DELETE SET NULL — ยอมให้ FK เป็น NULL ได้ แต่ห้ามเปลี่ยนไปชี้คนอื่น
        DB::table('audit_logs')->where('id', $id)->update(['actor_id' => null]);
        $this->assertNull(DB::table('audit_logs')->where('id', $id)->value('actor_id'));

        $other = User::factory()->create();
        $second = $this->auditRow($user->id);
        $this->assertBlocked(fn () => DB::table('audit_logs')->where('id', $second)->update(['actor_id' => $other->id]));
    }

    public function test_leave_action_log_cannot_be_rewritten_but_follows_a_deleted_leave(): void
    {
        $user = User::factory()->create();
        $type = LeaveType::create(['name' => 'ลาทดสอบประวัติ', 'max_days' => 10]);
        $leave = Leave::create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'start_date' => today(), 'end_date' => today(),
            'total_days' => 1, 'reason' => 'ทดสอบ', 'status' => 'Pending',
        ]);
        $log = LeaveActionLog::create(['leave_id' => $leave->id, 'user_id' => $user->id, 'action' => 'Created']);

        $this->assertBlocked(fn () => DB::table('leave_action_logs')->where('id', $log->id)->update(['action' => 'Cancelled']));

        $leave->forceDelete();
        $this->assertFalse(DB::table('leave_action_logs')->where('id', $log->id)->exists(), 'ลบตามใบลาที่ถูกลบจริงได้ (ON DELETE CASCADE)');
    }

    public function test_protect_command_reports_and_reinstalls_missing_triggers(): void
    {
        $this->artisan('audit:protect --check')
            ->expectsOutputToContain('ได้รับการป้องกันครบทุกตาราง')
            ->assertSuccessful();

        AuditLogGuard::remove();
        $this->artisan('audit:protect --check')
            ->expectsOutputToContain('ตารางที่ยังไม่ได้ป้องกัน')
            ->assertFailed();

        $this->artisan('audit:protect')->assertSuccessful();
        $this->assertSame([], AuditLogGuard::unprotectedTables());
        $id = $this->auditRow();
        $this->assertBlocked(fn () => DB::table('audit_logs')->where('id', $id)->delete());
    }

    public function test_down_removes_the_triggers(): void
    {
        $migration = require database_path('migrations/2026_10_19_130000_make_audit_logs_append_only.php');
        $id = $this->auditRow();

        $migration->down();
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'leave.rejected']);
        $this->assertSame('leave.rejected', DB::table('audit_logs')->where('id', $id)->value('action'));

        $migration->up();
        $this->assertBlocked(fn () => DB::table('audit_logs')->where('id', $id)->delete());
    }
}
