<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceRawEvent;
use App\Models\AttendanceSource;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Time\WorkSchedule;
use App\Models\Time\WorkScheduleAssignment;
use App\Models\Time\WorkScheduleDay;
use App\Models\User;
use App\Services\Attendance\AttendanceEventRecorder;
use App\Services\Attendance\AttendanceReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AttendanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['attendance.checkin', 'attendance.view_self', 'attendance.view_all'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->source = AttendanceSource::create([
            'code' => 'import-test', 'name' => 'ทดสอบนำเข้า', 'type' => 'import',
            'timezone' => 'Asia/Bangkok', 'health_state' => 'healthy',
        ]);
    }

    public function test_duplicate_event_is_stored_and_reconciled_only_once(): void
    {
        $user = User::factory()->create();
        $recorder = app(AttendanceEventRecorder::class);
        $at = CarbonImmutable::parse('2026-08-14 08:00:00', 'Asia/Bangkok');

        $first = $recorder->record($this->source, 'check_in', $at, 'vendor-100', [], $user, eventUuid: (string) Str::uuid());
        $second = $recorder->record($this->source, 'check_in', $at, 'vendor-100', [], $user, eventUuid: (string) Str::uuid());
        app(AttendanceReconciliationService::class)->reconcile($first['event']);
        app(AttendanceReconciliationService::class)->reconcile($second['event']);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertDatabaseCount('attendance_raw_events', 1);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_event_uuid_is_idempotent_and_raw_payload_is_immutable(): void
    {
        $user = User::factory()->create();
        $recorder = app(AttendanceEventRecorder::class);
        $uuid = (string) Str::uuid();
        $at = CarbonImmutable::parse('2026-08-14 08:00:00', 'Asia/Bangkok');

        $first = $recorder->record($this->source, 'check_in', $at, 'key-a', ['value' => 1], $user, eventUuid: $uuid);
        $second = $recorder->record($this->source, 'check_in', $at, 'key-b', ['value' => 2], $user, eventUuid: $uuid);

        $this->assertFalse($second['created']);
        $this->assertSame($first['event']->id, $second['event']->id);
        $this->expectException(LogicException::class);
        $first['event']->update(['payload' => ['value' => 99]]);
    }

    public function test_out_of_order_checkout_then_checkin_reconciles_one_result(): void
    {
        $user = User::factory()->create();
        $service = app(AttendanceReconciliationService::class);

        $checkout = $this->event($user, 'check_out', '2026-08-14 17:00:00', 'out-1');
        $checkin = $this->event($user, 'check_in', '2026-08-14 08:00:00', 'in-1');
        $service->reconcile($checkout);
        $attendance = $service->reconcile($checkin);

        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame('2026-08-14 08:00', $attendance->check_in_time->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
        $this->assertSame('2026-08-14 17:00', $attendance->check_out_time->timezone('Asia/Bangkok')->format('Y-m-d H:i'));
    }

    public function test_web_checkin_and_checkout_require_permission_and_create_raw_events(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 08:15:00', 'Asia/Bangkok'));
        $denied = User::factory()->create();
        $this->actingAs($denied)->postJson('/api/attendances/check-in')->assertForbidden();

        $user = User::factory()->create();
        $user->givePermissionTo('attendance.checkin');
        $this->actingAs($user)->postJson('/api/attendances/check-in')->assertOk();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 16:45:00', 'Asia/Bangkok'));
        $this->actingAs($user)->postJson('/api/attendances/check-out')->assertOk();

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('attendance_raw_events', 2);
        $this->assertSame(2, AttendanceRawEvent::whereNotNull('processed_at')->count());
        CarbonImmutable::setTestNow();
    }

    public function test_overnight_checkout_belongs_to_previous_work_date(): void
    {
        $user = User::factory()->create();
        $schedule = WorkSchedule::create([
            'code' => 'night', 'name' => 'กะกลางคืน', 'timezone' => 'Asia/Bangkok',
            'grace_minutes' => 0, 'early_leave_grace_minutes' => 0, 'is_default' => false, 'is_active' => true,
        ]);
        foreach (range(0, 6) as $day) {
            WorkScheduleDay::create([
                'work_schedule_id' => $schedule->id, 'day_of_week' => $day, 'is_working_day' => true,
                'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true,
            ]);
        }
        WorkScheduleAssignment::create([
            'work_schedule_id' => $schedule->id, 'user_id' => $user->id, 'effective_from' => '2020-01-01',
        ]);

        $service = app(AttendanceReconciliationService::class);
        $service->reconcile($this->event($user, 'check_in', '2026-08-14 22:00:00', 'night-in'));
        $attendance = $service->reconcile($this->event($user, 'check_out', '2026-08-15 06:00:00', 'night-out'));

        $this->assertSame('2026-08-14', $attendance->date->toDateString());
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_missing_checkout_remains_a_valid_partial_result(): void
    {
        $user = User::factory()->create();
        $attendance = app(AttendanceReconciliationService::class)
            ->reconcile($this->event($user, 'check_in', '2026-08-14 08:00:00', 'missing-out'));

        $this->assertNotNull($attendance->check_in_time);
        $this->assertNull($attendance->check_out_time);
    }

    public function test_manual_correction_uses_workflow_and_writes_audit(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('attendance.view_self');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('attendance.view_all');
        $attendance = Attendance::create(['user_id' => $owner->id, 'date' => '2026-08-14', 'status' => 'Present']);

        $response = $this->actingAs($owner)->postJson("/api/attendances/{$attendance->uuid}/corrections", [
            'changes' => ['status' => 'Late', 'notes' => 'แก้จากหลักฐาน'],
            'reason' => 'เวลาจากระบบต้นทางคลาดเคลื่อน',
        ])->assertCreated();

        $correctionUuid = $response->json('correction.uuid');
        $this->actingAs($reviewer)
            ->postJson("/api/admin/attendance-corrections/{$correctionUuid}/approve", ['comment' => 'ตรวจแล้ว'])
            ->assertOk();

        $this->assertSame('Late', $attendance->fresh()->status);
        $this->assertDatabaseHas('attendance_corrections', ['uuid' => $correctionUuid, 'status' => 'approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.manual_correction.applied', 'resource_id' => (string) $attendance->id]);
    }

    /*
     * CLEANUP-02 — รายการคำขอแก้ไขเวลา
     *
     * เดิมมีแต่ endpoint สร้างและตัดสิน ไม่มี endpoint ให้ "ดูรายการ" หน้าจอจึงสร้างไม่ได้
     * และทั้งฟีเจอร์ไม่มีทางเข้าเลยแม้สายอนุมัติกับ audit จะทำงานได้ครบ
     */
    public function test_a_person_sees_only_their_own_correction_requests(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('attendance.view_self');
        $someoneElse = User::factory()->create();
        $someoneElse->givePermissionTo('attendance.view_self');

        $this->requestCorrection($owner);
        $this->requestCorrection($someoneElse);

        $rows = $this->actingAs($owner)->getJson('/api/attendance-corrections')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($owner->id, $rows[0]['user_id']);
    }

    public function test_the_reviewer_worklist_lists_pending_requests_and_respects_scope(): void
    {
        $unitA = $this->orgUnit('CORR-A');
        $unitB = $this->orgUnit('CORR-B');

        $reviewer = User::factory()->create(['org_unit_id' => $unitA])->fresh();
        $reviewer->givePermissionTo('attendance.view_all');
        DB::table('permission_scopes')->insert([
            'model_type' => $reviewer->getMorphClass(), 'model_id' => $reviewer->id,
            'permission_name' => 'attendance.view_all', 'scope' => 'UNIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $visible = User::factory()->create(['org_unit_id' => $unitA])->fresh();
        $visible->givePermissionTo('attendance.view_self');
        $hidden = User::factory()->create(['org_unit_id' => $unitB])->fresh();
        $hidden->givePermissionTo('attendance.view_self');

        $this->requestCorrection($visible);
        $this->requestCorrection($hidden);

        $rows = $this->actingAs($reviewer)->getJson('/api/admin/attendance-corrections')->assertOk()->json('data');

        $this->assertSame([$visible->id], array_column($rows, 'user_id'));
    }

    public function test_the_reviewer_worklist_requires_the_view_all_permission(): void
    {
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('attendance.view_self');

        $this->actingAs($outsider)->getJson('/api/admin/attendance-corrections')->assertForbidden();
    }

    private function requestCorrection(User $owner): void
    {
        $attendance = Attendance::create(['user_id' => $owner->id, 'date' => '2026-08-14', 'status' => 'Present']);

        $this->actingAs($owner)->postJson("/api/attendances/{$attendance->uuid}/corrections", [
            'changes' => ['status' => 'Late'],
            'reason' => 'ทดสอบคำขอ',
        ])->assertCreated();
    }

    public function test_view_all_scope_filters_other_units(): void
    {
        $unitA = $this->orgUnit('ATT-A');
        $unitB = $this->orgUnit('ATT-B');
        $viewer = User::factory()->create(['org_unit_id' => $unitA])->fresh();
        $viewer->givePermissionTo('attendance.view_all');
        DB::table('permission_scopes')->insert([
            'model_type' => $viewer->getMorphClass(), 'model_id' => $viewer->id,
            'permission_name' => 'attendance.view_all', 'scope' => 'UNIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $inside = User::factory()->create(['org_unit_id' => $unitA])->fresh();
        $outside = User::factory()->create(['org_unit_id' => $unitB])->fresh();
        Attendance::create(['user_id' => $inside->id, 'date' => '2026-08-14']);
        Attendance::create(['user_id' => $outside->id, 'date' => '2026-08-14']);

        $response = $this->actingAs($viewer)->getJson('/api/admin/attendances')->assertOk();
        $this->assertSame([$inside->id], collect($response->json('data'))->pluck('user_id')->all());
    }

    public function test_leave_sync_cannot_mutate_attendance_outside_scope(): void
    {
        $unitA = $this->orgUnit('SYNC-A');
        $unitB = $this->orgUnit('SYNC-B');
        $viewer = User::factory()->create(['org_unit_id' => $unitA])->fresh();
        $viewer->givePermissionTo('attendance.view_all');
        DB::table('permission_scopes')->insert([
            'model_type' => $viewer->getMorphClass(), 'model_id' => $viewer->id,
            'permission_name' => 'attendance.view_all', 'scope' => 'UNIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $inside = User::factory()->create(['org_unit_id' => $unitA])->fresh();
        $outside = User::factory()->create(['org_unit_id' => $unitB])->fresh();
        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);
        foreach ([$inside, $outside] as $employee) {
            Leave::create([
                'user_id' => $employee->id, 'leave_type_id' => $type->id,
                'start_date' => '2026-08-14', 'end_date' => '2026-08-14',
                'total_days' => 1, 'reason' => 'ทดสอบ scope', 'status' => 'Approved',
            ]);
        }

        $this->actingAs($viewer)->postJson('/api/admin/attendances/sync-leaves', [
            'month' => 8, 'year' => 2026,
        ])->assertOk();

        $this->assertTrue(Attendance::where('user_id', $inside->id)->whereDate('date', '2026-08-14')->where('status', 'Leave')->exists());
        $this->assertFalse(Attendance::where('user_id', $outside->id)->whereDate('date', '2026-08-14')->exists());
    }

    private function event(User $user, string $type, string $localTime, string $dedup): AttendanceRawEvent
    {
        return app(AttendanceEventRecorder::class)->record(
            $this->source, $type, CarbonImmutable::parse($localTime, 'Asia/Bangkok'), $dedup, [], $user,
        )['event'];
    }

    private function orgUnit(string $code): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(), 'code' => $code, 'name' => $code,
            'type' => 'department', 'effective_from' => '2020-01-01', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
