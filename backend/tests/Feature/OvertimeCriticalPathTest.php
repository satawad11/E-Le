<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Overtime\OvertimeAssignment;
use App\Models\Overtime\OvertimeOrder;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OvertimeCriticalPathTest extends TestCase
{
    use RefreshDatabase;

    private int $unit;

    private User $creator;

    private User $reviewer;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('overtime.operation.execute', 'web');
        Permission::findOrCreate('overtime.management.review', 'web');
        Permission::findOrCreate('attendance.ot_summary', 'web');
        Permission::findOrCreate('attendance.checkin', 'web');
        $this->unit = $this->orgUnit('OT-A');
        $this->creator = User::factory()->create(['org_unit_id' => $this->unit])->fresh();
        $this->creator->givePermissionTo('overtime.operation.execute');
        $this->reviewer = User::factory()->create(['org_unit_id' => $this->unit])->fresh();
        // RBAC-CLEANUP-01: ผู้อนุญาต/รับรอง OT ถือสิทธิ์อนุมัติโดยตรง — สิทธิ์ดูสรุปไม่ได้ให้สิทธิ์อนุมัติอีกแล้ว
        $this->reviewer->givePermissionTo(['overtime.management.review', 'attendance.ot_summary']);
        $this->employee = User::factory()->create(['org_unit_id' => $this->unit])->fresh();
    }

    public function test_critical_path_keeps_approved_actual_and_certified_time_distinct(): void
    {
        $order = $this->createApproveAndAssign();
        Attendance::create(['user_id' => $this->employee->id, 'date' => '2026-08-17', 'status' => 'Present']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 18:15:00', 'Asia/Bangkok'));
        $checkIn = $this->actingAs($this->employee)
            ->postJson('/api/attendances/ot-check-in', ['notes' => 'เริ่มงานจริง'])
            ->assertOk();
        $recordUuid = $checkIn->json('ot_time_record.uuid');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 19:45:00', 'Asia/Bangkok'));
        $this->actingAs($this->employee)->postJson('/api/attendances/ot-check-out')->assertOk();

        $this->actingAs($this->reviewer)->postJson("/api/overtime/time-records/{$recordUuid}/certify", [
            'certified_start' => '2026-08-17 18:30:00',
            'certified_end' => '2026-08-17 19:30:00',
            'reason' => 'รับรองตามช่วงที่ปฏิบัติงานตามหลักฐาน',
        ])->assertOk();

        $record = OvertimeTimeRecord::where('uuid', $recordUuid)->firstOrFail();
        $this->assertSame(120, (int) $order->approved_start->diffInMinutes($order->approved_end));
        $this->assertSame(90, $record->actual_minutes);
        $this->assertSame(60, $record->certified_minutes);
        $this->assertSame('matched', $record->reconciliation_status);
        $this->assertNotNull($record->attendance_id);
        $this->assertNull(Attendance::firstOrFail()->getRawOriginal('ot_check_in_time'));
        $this->assertNull(Attendance::firstOrFail()->getRawOriginal('ot_check_out_time'));

        $report = $this->actingAs($this->reviewer)
            ->getJson('/api/overtime/reports?year=2026&month=8')
            ->assertOk()->json('data.0');
        $this->assertSame(120, $report['approved_minutes']);
        $this->assertSame(90, $report['actual_minutes']);
        $this->assertSame(60, $report['certified_minutes']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'overtime.time.certified', 'resource_id' => (string) $record->id]);
        CarbonImmutable::setTestNow();
    }

    /**
     * OT-PUNCH-01: ผู้ปฏิบัติงานลงเวลา OT ด้วยสิทธิ์ลงเวลาปกติ แต่ออกคำสั่ง OT เองไม่ได้
     */
    public function test_assigned_worker_punches_ot_without_the_order_issuing_permission(): void
    {
        $this->createApproveAndAssign();
        $this->assertFalse($this->employee->fresh()->can('overtime.operation.execute'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 18:15:00', 'Asia/Bangkok'));
        $this->actingAs($this->employee)->postJson('/api/attendances/ot-check-in')->assertOk();
        $this->actingAs($this->employee)->postJson('/api/overtime/orders', [
            'title' => 'ออกคำสั่งเอง', 'org_unit_id' => $this->unit,
        ])->assertForbidden();
        CarbonImmutable::setTestNow();
    }

    public function test_ot_punch_still_requires_the_normal_check_in_permission(): void
    {
        $outsider = User::factory()->create(['org_unit_id' => $this->unit])->fresh();

        $this->actingAs($outsider)->postJson('/api/attendances/ot-check-in')->assertForbidden();
    }

    public function test_overtime_punch_requires_an_approved_assignment(): void
    {
        $this->employee->givePermissionTo('attendance.checkin');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 18:30:00', 'Asia/Bangkok'));

        $this->actingAs($this->employee)
            ->postJson('/api/attendances/ot-check-in')
            ->assertBadRequest()
            ->assertJsonPath('message', 'คุณไม่มีคำสั่ง OT ที่ได้รับอนุมัติในช่วงเวลานี้');

        $this->assertDatabaseCount('ot_time_records', 0);
        CarbonImmutable::setTestNow();
    }

    public function test_pending_order_cannot_be_used_until_workflow_approval(): void
    {
        $order = $this->createOrder();
        $this->assertSame('pending', $order->status);
        $this->assertDatabaseHas('workflow_instances', [
            'subject_type' => $order->getMorphClass(), 'subject_id' => $order->id,
            'status' => 'running', 'is_shadow' => false,
        ]);

        $this->actingAs($this->reviewer)
            ->postJson("/api/overtime/orders/{$order->uuid}/assignments", ['user_ids' => [$this->employee->id]])
            ->assertUnprocessable();
        $this->assertDatabaseCount('ot_assignments', 0);
    }

    public function test_order_rejection_closes_workflow_and_prevents_assignment(): void
    {
        $order = $this->createOrder();
        $this->actingAs($this->reviewer)
            ->postJson("/api/overtime/orders/{$order->uuid}/reject", ['comment' => 'ยังไม่มีความจำเป็น'])
            ->assertOk();

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertDatabaseHas('workflow_instances', [
            'subject_type' => $order->getMorphClass(), 'subject_id' => $order->id, 'status' => 'rejected',
        ]);
    }

    public function test_report_and_certification_respect_org_scope(): void
    {
        $otherUnit = $this->orgUnit('OT-B');
        DB::table('permission_scopes')->insert([
            'model_type' => $this->reviewer->getMorphClass(), 'model_id' => $this->reviewer->id,
            'permission_name' => 'overtime.management.review', 'scope' => 'UNIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherUser = User::factory()->create(['org_unit_id' => $otherUnit])->fresh();
        $otherOrder = OvertimeOrder::create([
            'title' => 'OT หน่วยงานอื่น', 'org_unit_id' => $otherUnit, 'timezone' => 'Asia/Bangkok',
            'scheduled_start' => '2026-08-17 18:00:00', 'scheduled_end' => '2026-08-17 20:00:00',
            'approved_start' => '2026-08-17 18:00:00', 'approved_end' => '2026-08-17 20:00:00',
            'status' => 'approved', 'created_by' => $otherUser->id,
        ]);
        $assignment = OvertimeAssignment::create([
            'ot_order_id' => $otherOrder->id, 'user_id' => $otherUser->id,
            'status' => 'completed', 'assigned_at' => now(),
        ]);
        $record = OvertimeTimeRecord::create([
            'ot_assignment_id' => $assignment->id, 'user_id' => $otherUser->id,
            'actual_start' => '2026-08-17 18:00:00', 'actual_end' => '2026-08-17 19:00:00',
            'actual_minutes' => 60, 'status' => 'recorded',
        ]);

        $this->actingAs($this->reviewer)->getJson('/api/overtime/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->reviewer)->postJson("/api/overtime/time-records/{$record->uuid}/certify", [
            'certified_start' => '2026-08-17 18:00:00', 'certified_end' => '2026-08-17 19:00:00',
            'reason' => 'ไม่ควรผ่าน',
        ])->assertForbidden();
    }

    public function test_schema_has_no_compensation_calculation_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('ot_orders', ['approved_start', 'approved_end']));
        $this->assertTrue(Schema::hasColumns('ot_time_records', [
            'actual_start', 'actual_end', 'certified_start', 'certified_end',
        ]));
        foreach (['rate', 'amount', 'compensation', 'pay'] as $column) {
            $this->assertFalse(Schema::hasColumn('ot_time_records', $column));
        }
    }

    public function test_legacy_attendance_overtime_fields_are_read_only(): void
    {
        $attendance = Attendance::create(['user_id' => $this->employee->id, 'date' => '2026-08-17']);

        $this->expectException(LogicException::class);
        $attendance->ot_minutes = 120;
        $attendance->save();
    }

    private function createApproveAndAssign(): OvertimeOrder
    {
        $order = $this->createOrder();

        /*
         * สายอนุมัติคำสั่ง OT มีสองขั้นตามระเบียบ (เจ้าของระบบกำหนด 2026-08-19):
         * รอง ผอ. ฝ่ายทรัพยากร "อนุญาต" ก่อน แล้ว ผอ. จึง "อนุมัติ" ปิดท้าย
         * คำสั่งจะยังเป็น pending จนกว่าจะผ่านครบทั้งสองขั้น
         */
        $window = [
            'approved_start' => '2026-08-17 18:00:00',
            'approved_end' => '2026-08-17 20:00:00',
        ];

        $this->actingAs($this->reviewer)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", $window + ['comment' => 'อนุญาตตามคำขอ'])
            ->assertOk();

        $this->assertSame('pending', $order->fresh()->status, 'ขั้นแรกยังไม่ทำให้คำสั่งใช้งานได้');

        $this->actingAs($this->reviewer)
            ->postJson("/api/overtime/orders/{$order->uuid}/approve", $window + ['comment' => 'อนุมัติตามคำสั่ง'])
            ->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
        $this->actingAs($this->reviewer)->postJson("/api/overtime/orders/{$order->uuid}/assignments", [
            'user_ids' => [$this->employee->id],
        ])->assertOk();
        $this->employee->givePermissionTo('attendance.checkin');

        return $order->fresh();
    }

    private function createOrder(): OvertimeOrder
    {
        $response = $this->actingAs($this->creator)->postJson('/api/overtime/orders', [
            'title' => 'ปฏิบัติงานจัดทำรายงาน', 'org_unit_id' => $this->unit,
            'scheduled_start' => '2026-08-17 18:00:00',
            'scheduled_end' => '2026-08-17 20:00:00',
        ])->assertCreated();

        return OvertimeOrder::where('uuid', $response->json('order.uuid'))->firstOrFail();
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
