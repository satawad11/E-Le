<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Overtime\OvertimeAssignment;
use App\Models\Overtime\OvertimeOrder;
use App\Models\Overtime\OvertimeTimeRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReportingLayerTest extends TestCase
{
    use RefreshDatabase;

    private int $unitA;

    private int $unitB;

    private User $viewer;

    private User $employeeA;

    private User $employeeB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view_users', 'leave.report.view', 'attendance.view_all', 'attendance.ot_summary'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->unitA = $this->orgUnit('REPORT-A');
        $this->unitB = $this->orgUnit('REPORT-B');
        $this->viewer = User::factory()->create(['name' => 'Scoped Reviewer', 'org_unit_id' => $this->unitA, 'is_active' => true])->fresh();
        $this->employeeA = User::factory()->create(['name' => 'Visible Employee', 'org_unit_id' => $this->unitA, 'is_active' => true])->fresh();
        $this->employeeB = User::factory()->create(['name' => 'Hidden Employee', 'org_unit_id' => $this->unitB, 'is_active' => true])->fresh();
    }

    public function test_each_report_requires_its_normal_module_permission(): void
    {
        $this->actingAs($this->viewer)->getJson('/api/reporting/personnel')->assertForbidden();
        $this->viewer->givePermissionTo('view_users');
        $this->actingAs($this->viewer)->getJson('/api/reporting/leave')->assertForbidden();
        $this->actingAs($this->viewer)->getJson('/api/reporting/attendance')->assertForbidden();
        $this->actingAs($this->viewer)->getJson('/api/reporting/ot')->assertForbidden();
    }

    public function test_personnel_leave_and_attendance_reports_never_cross_explicit_scope(): void
    {
        $this->grantScoped('view_users');
        $this->grantScoped('leave.report.view');
        $this->grantScoped('attendance.view_all');

        $leaveType = DB::table('leave_types')->insertGetId([
            'name' => 'Sick', 'max_days' => 30, 'requires_doc' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->employeeA, $this->employeeB] as $employee) {
            DB::table('leaves')->insert([
                'uuid' => (string) Str::uuid(), 'user_id' => $employee->id, 'leave_type_id' => $leaveType,
                'start_date' => '2026-01-10', 'end_date' => '2026-01-10', 'total_days' => 1,
                'reason' => 'test', 'status' => 'Approved', 'approval_level' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Attendance::create([
                'user_id' => $employee->id, 'date' => '2026-01-10', 'status' => 'Late',
                'check_in_time' => '2026-01-10 08:30:00', 'late_minutes' => 30,
            ]);
        }

        foreach (['personnel', 'leave', 'attendance', 'late'] as $report) {
            $response = $this->actingAs($this->viewer)->getJson("/api/reporting/{$report}?from=2026-01-01&to=2026-01-31")
                ->assertOk();
            $names = collect($response->json('rows'))->pluck('name');
            $this->assertTrue($names->contains('Visible Employee'), $report);
            $this->assertFalse($names->contains('Hidden Employee'), $report);
        }

        $this->actingAs($this->viewer)
            ->getJson("/api/reporting/personnel?org_unit_id={$this->unitB}")
            ->assertForbidden();
    }

    public function test_evaluation_returns_raw_statistics_without_an_undefined_formula(): void
    {
        $this->grantScoped('leave.report.view');
        $this->grantScoped('attendance.view_all');
        Attendance::create(['user_id' => $this->employeeA->id, 'date' => '2026-02-01', 'status' => 'Absent']);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/reporting/evaluation-metrics?from=2026-02-01&to=2026-02-28')
            ->assertOk()
            ->assertJsonPath('meta.formula', null)
            ->assertJsonPath('meta.metrics_only', true);

        $row = collect($response->json('rows'))->firstWhere('user_id', $this->employeeA->id);
        $this->assertSame(1, $row['absence_days']);
        $this->assertArrayNotHasKey('score', $row);
        $this->assertArrayNotHasKey('passed', $row);
        $this->assertFalse(collect($response->json('rows'))->contains('user_id', $this->employeeB->id));
    }

    public function test_pdf_excel_word_and_csv_exports_keep_response_contract_and_audit(): void
    {
        $this->grantScoped('view_users');

        $pdf = $this->actingAs($this->viewer)->get('/api/reporting/personnel/export/pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $csv = $this->actingAs($this->viewer)->get('/api/reporting/personnel/export/csv');
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Visible Employee', $csv->getContent());
        $this->assertStringNotContainsString('Hidden Employee', $csv->getContent());

        foreach (['xlsx', 'docx'] as $format) {
            $response = $this->actingAs($this->viewer)->get("/api/reporting/personnel/export/{$format}");
            $response->assertOk();
            $this->assertStringContainsString("personnel-report.{$format}", (string) $response->headers->get('content-disposition'));
        }
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'report.exported')->count());
    }

    public function test_overtime_report_keeps_approved_actual_and_certified_time_distinct(): void
    {
        $this->grantScoped('attendance.ot_summary');
        $order = OvertimeOrder::create([
            'title' => 'Reporting OT', 'org_unit_id' => $this->unitA, 'timezone' => 'Asia/Bangkok',
            'scheduled_start' => '2026-03-01 18:00:00', 'scheduled_end' => '2026-03-01 20:00:00',
            'approved_start' => '2026-03-01 18:00:00', 'approved_end' => '2026-03-01 20:00:00',
            'status' => 'approved', 'created_by' => $this->viewer->id,
        ]);
        $assignment = OvertimeAssignment::create([
            'ot_order_id' => $order->id, 'user_id' => $this->employeeA->id,
            'status' => 'completed', 'assigned_at' => now(),
        ]);
        OvertimeTimeRecord::create([
            'ot_assignment_id' => $assignment->id, 'user_id' => $this->employeeA->id,
            'actual_start' => '2026-03-01 18:15:00', 'actual_end' => '2026-03-01 19:45:00',
            'actual_minutes' => 90, 'certified_start' => '2026-03-01 18:30:00',
            'certified_end' => '2026-03-01 19:30:00', 'certified_minutes' => 60, 'status' => 'certified',
        ]);

        $row = $this->actingAs($this->viewer)
            ->getJson('/api/reporting/ot?from=2026-03-01&to=2026-03-02')
            ->assertOk()->json('rows.0');
        $this->assertSame(120, $row['approved_minutes']);
        $this->assertSame(90, $row['actual_minutes']);
        $this->assertSame(60, $row['certified_minutes']);
    }

    public function test_personnel_report_uses_a_bounded_number_of_queries(): void
    {
        $this->grantScoped('view_users');
        User::factory()->count(20)->create(['org_unit_id' => $this->unitA, 'is_active' => true]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->viewer)->getJson('/api/reporting/personnel')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, count($queries));
    }

    private function grantScoped(string $permission): void
    {
        $this->viewer->givePermissionTo($permission);
        DB::table('permission_scopes')->insert([
            'model_type' => $this->viewer->getMorphClass(), 'model_id' => $this->viewer->id,
            'permission_name' => $permission, 'scope' => 'UNIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orgUnit(string $code): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(), 'code' => $code, 'name' => $code, 'type' => 'department',
            'effective_from' => now()->toDateString(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
