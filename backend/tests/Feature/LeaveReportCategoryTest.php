<?php

namespace Tests\Feature;

use App\Models\LeaveType;
use App\Models\User;
use App\Services\DocumentEngine\Providers\LeaveStatisticsDataProvider;
use App\Services\Reporting\LeaveReportCategoryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * REPORT-005 — รายงานสถิติการลาต้องไม่ผูกกับ "ชื่อไทย" ของประเภทการลา
 *
 * เดิมช่องของรายงานมาจาก `leave_types.name LIKE '%ป่วย%'` ที่ฝังอยู่ใน SQL
 * ผู้ดูแลเปลี่ยนชื่อประเภทการลาจากหน้าจอเมื่อไร ยอดในรายงานก็หายไปเงียบ ๆ เมื่อนั้น
 * โดยไม่มีข้อผิดพลาดใด ๆ ให้เห็น
 */
class LeaveReportCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private User $employee;

    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('leave.report.view', 'web');

        $this->unit = DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(), 'code' => 'RPT', 'name' => 'RPT', 'type' => 'department',
            'effective_from' => now()->toDateString(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->viewer = User::factory()->create(['name' => 'Reviewer', 'org_unit_id' => $this->unit, 'is_active' => true])->fresh();
        $this->employee = User::factory()->create(['name' => 'Employee', 'org_unit_id' => $this->unit, 'is_active' => true])->fresh();

        $this->viewer->givePermissionTo('leave.report.view');
        DB::table('permission_scopes')->insert([
            'model_type' => $this->viewer->getMorphClass(), 'model_id' => $this->viewer->id,
            'permission_name' => 'leave.report.view', 'scope' => 'UNIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_renaming_a_leave_type_does_not_change_the_report(): void
    {
        $sick = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'ลป.', 'max_days' => 60]);
        $this->approvedLeave($sick, 3);

        $before = $this->runReport();
        $this->assertSame(3.0, $before['rows'][0]['sick_days']);

        // ผู้ดูแลเปลี่ยนชื่อให้ตรงกับระเบียบของหน่วยงาน โดยไม่มีคำว่า "ป่วย" อยู่เลย
        $sick->update(['name' => 'ลาเนื่องจากเจ็บไข้ได้ป่ว']);

        $after = $this->runReport();
        $this->assertSame(3.0, $after['rows'][0]['sick_days'], 'การเปลี่ยนชื่อประเภทการลาต้องไม่ทำให้ยอดในรายงานหายไป');
        $this->assertSame(3.0, $after['totals']['sick_days']);
    }

    public function test_each_category_counts_only_its_own_leave_type(): void
    {
        $sick = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => 'ลป.', 'max_days' => 60]);
        $personal = LeaveType::create(['name' => 'ลากิจส่วนตัว', 'code_prefix' => 'ลก.', 'max_days' => 45]);
        $vacation = LeaveType::create(['name' => 'ลาพักผ่อน', 'code_prefix' => 'ลพ.', 'max_days' => 10]);

        $this->approvedLeave($sick, 1);
        $this->approvedLeave($personal, 2);
        $this->approvedLeave($vacation, 4);

        $row = $this->runReport()['rows'][0];

        $this->assertSame(1.0, $row['sick_days']);
        $this->assertSame(2.0, $row['personal_days']);
        $this->assertSame(4.0, $row['vacation_days']);
        $this->assertSame(7.0, $row['total_days']);
    }

    /**
     * "ลาไปช่วยเหลือภริยาที่คลอดบุตร" เคยถูกนับรวมเข้าช่องอื่นเพราะชื่อมีคำที่ชนกัน
     * ตอนนี้ประเภทที่ไม่ได้อยู่ในกลุ่มใดจะเข้าเฉพาะยอดรวม ไม่ไปโผล่ผิดช่อง
     */
    public function test_a_leave_type_outside_every_category_only_hits_the_grand_total(): void
    {
        $paternity = LeaveType::create([
            'name' => 'ลาไปช่วยเหลือภริยาที่คลอดบุตร', 'code_prefix' => 'ลช.', 'max_days' => 15,
        ]);

        $this->approvedLeave($paternity, 5);

        $row = $this->runReport()['rows'][0];

        $this->assertSame(0.0, $row['sick_days']);
        $this->assertSame(0.0, $row['personal_days']);
        $this->assertSame(0.0, $row['vacation_days']);
        $this->assertSame(5.0, $row['total_days']);
    }

    public function test_the_mapping_is_configurable_without_touching_code(): void
    {
        $custom = LeaveType::create(['name' => 'ลาเพื่อรักษาพยาบาล', 'code_prefix' => 'MED', 'max_days' => 30]);
        $this->approvedLeave($custom, 2);

        // ก่อนตั้งค่า: ไม่มีกลุ่มไหนรับ
        $this->assertSame(0.0, $this->runReport()['rows'][0]['sick_days']);

        config(['leave_reporting.categories.sick_days.codes' => ['ลป.', 'MED']]);
        $this->refreshResolver();

        $this->assertSame(2.0, $this->runReport()['rows'][0]['sick_days']);
    }

    /**
     * ประเภทการลาเก่าที่ยังไม่มีรหัสยังนับได้ผ่านทางถอย (ชื่อ) เพื่อไม่ให้ข้อมูล
     * ที่มีอยู่ก่อนหน้าหายไปตอนอัปเกรด
     */
    public function test_legacy_leave_types_without_a_code_still_fall_back_to_the_name(): void
    {
        $legacy = LeaveType::create(['name' => 'ลาป่วย', 'code_prefix' => null, 'max_days' => 60]);
        $this->approvedLeave($legacy, 2);

        $this->assertSame(2.0, $this->runReport()['rows'][0]['sick_days']);
    }

    private function approvedLeave(LeaveType $type, float $days): void
    {
        DB::table('leaves')->insert([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'total_days' => $days,
            'reason' => 'test',
            'status' => 'Approved',
            'approval_level' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** resolver cache ผลลัพธ์ต่อ instance — สร้างใหม่เมื่อเปลี่ยน config ระหว่างเทสต์ */
    private function refreshResolver(): void
    {
        $this->app->forgetInstance(LeaveReportCategoryResolver::class);
        $this->app->forgetInstance(LeaveStatisticsDataProvider::class);
    }

    /**
     * @return array{rows: array<int,array<string,mixed>>, totals: array<string,float>}
     */
    private function runReport(): array
    {
        $this->refreshResolver();

        $dataset = $this->app->make(LeaveStatisticsDataProvider::class)->dataset([
            'viewer' => $this->viewer->fresh(),
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]);

        return $dataset->toArray();
    }
}
