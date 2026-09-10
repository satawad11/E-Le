<?php

namespace Tests\Feature;

use App\Models\Document\DocumentReportDefinition;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DocumentEngine\Providers\LeaveStatisticsDataProvider;
use App\Services\DocumentEngine\ReportEngine;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report Engine — นิยามรายงานจากหน้าจอ การจัดกลุ่ม ยอดรวม และการส่งออก
 */
class DocumentReportEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentEngineSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function leavesForTwoDepartments(): void
    {
        $sick = LeaveType::create(['name' => 'ลาป่วย']);
        $vacation = LeaveType::create(['name' => 'ลาพักผ่อน']);

        foreach ([['ฝ่ายวิชาการ', 2.0], ['ฝ่ายบริหาร', 3.0]] as [$deptName, $days]) {
            $dept = OrgUnit::factory()->create(['name' => $deptName]);
            $user = User::factory()->create(['org_unit_id' => $dept->id, 'is_active' => true]);

            foreach ([$sick, $vacation] as $type) {
                Leave::create([
                    'user_id' => $user->id, 'leave_type_id' => $type->id,
                    'start_date' => now()->startOfMonth()->addDays(2)->toDateString(),
                    'end_date' => now()->startOfMonth()->addDays(3)->toDateString(),
                    'total_days' => $days, 'reason' => 'ทดสอบรายงาน',
                    'status' => 'Approved', 'approval_level' => 1,
                ]);
            }
        }
    }

    public function test_the_example_report_is_installed_and_uses_a_whitelisted_source(): void
    {
        $report = DocumentReportDefinition::where('code', 'LEAVE_MONTHLY_STATISTICS')->first();

        $this->assertNotNull($report);
        $this->assertSame(LeaveStatisticsDataProvider::CODE, $report->data_source_code);
        $this->assertSame('department', $report->grouping['column']);
    }

    public function test_it_groups_rows_and_computes_subtotals_and_a_grand_total(): void
    {
        $this->leavesForTwoDepartments();
        $admin = $this->admin();
        $report = DocumentReportDefinition::where('code', 'LEAVE_MONTHLY_STATISTICS')->firstOrFail();

        $result = app(ReportEngine::class)->run($report, $admin);

        $this->assertCount(2, $result['groups']);
        foreach ($result['groups'] as $group) {
            $this->assertArrayHasKey('subtotal', $group);
            $this->assertGreaterThan(0, $group['subtotal']['total_days']);
        }
        $this->assertSame(10.0, $result['totals']['total_days']);
    }

    public function test_an_admin_can_run_and_export_a_report(): void
    {
        $this->leavesForTwoDepartments();
        $admin = $this->admin();
        $report = DocumentReportDefinition::where('code', 'LEAVE_MONTHLY_STATISTICS')->firstOrFail();

        $this->actingAs($admin)->getJson("/api/admin/document-reports/{$report->id}/run")
            ->assertOk()
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('totals.total_days', 10);

        $pdf = $this->actingAs($admin)->get("/api/admin/document-reports/{$report->id}/export/pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($admin)->get("/api/admin/document-reports/{$report->id}/export/xlsx")->assertOk();
        $this->actingAs($admin)->get("/api/admin/document-reports/{$report->id}/export/csv")->assertOk();
    }

    public function test_an_admin_can_create_a_report_from_the_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/document-reports', [
            'code' => 'MY_CUSTOM_REPORT',
            'name_th' => 'รายงานของฉัน',
            'module' => 'leave',
            'data_source_code' => LeaveStatisticsDataProvider::CODE,
            'columns' => [['key' => 'employee', 'label' => 'ชื่อ'], ['key' => 'total_days', 'label' => 'รวมวันลา']],
            'aggregates' => [['column' => 'total_days', 'function' => 'SUM']],
        ])->assertOk();

        $this->assertDatabaseHas('document_report_definitions', ['code' => 'MY_CUSTOM_REPORT']);
    }

    public function test_an_unknown_data_source_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/document-reports', [
            'code' => 'BAD_REPORT', 'name_th' => 'รายงานอันตราย', 'module' => 'leave',
            'data_source_code' => 'SELECT * FROM users',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('document_report_definitions', ['code' => 'BAD_REPORT']);
    }

    public function test_reports_require_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $report = DocumentReportDefinition::where('code', 'LEAVE_MONTHLY_STATISTICS')->firstOrFail();

        $this->actingAs($outsider)->getJson('/api/admin/document-reports')->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/admin/document-reports/{$report->id}/run")->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/admin/document-reports', [
            'code' => 'X', 'name_th' => 'X', 'module' => 'leave', 'data_source_code' => LeaveStatisticsDataProvider::CODE,
        ])->assertForbidden();
    }
}
