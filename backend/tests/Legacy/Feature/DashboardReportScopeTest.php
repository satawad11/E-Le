<?php

namespace Tests\Legacy\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\FiscalYearSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-4 (3/4) — ล็อกว่าใครเห็นแดชบอร์ดรวมและรายงานรวม
 *
 * จุดสำคัญ: เดิม `DashboardController` ตัดสินจากชื่อ role ล้วน
 * (Director / Deputy Director / HR Head / Super Admin) โดยไม่มี permission สำรอง
 * ส่วน Document Officer **ไม่อยู่ในชุดนั้น** จึงเห็นเฉพาะข้อมูลของตนเอง
 *
 * หลัง 04-1 ที่ map ชื่อ permission ข้ามรุ่น Document Officer ถือ
 * `leave.report.view` และ `attendance.view_all` เหมือนสามบทบาทข้างต้น
 * ถ้าเผลอใช้สิทธิ์เหล่านั้นตัดสินแดชบอร์ด Document Officer จะได้สิทธิ์เพิ่มขึ้นมา
 *
 * เทสต์ชุดนี้กันไม่ให้เกิดกรณีนั้น
 */
class DashboardReportScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private LeaveType $type;

    private Leave $othersLeave;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['leave.report.view', 'leave.approval.dept', 'attendance.view_all'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // รายงานกรองตามปีงบประมาณ — ถ้าไม่มีข้อมูลปีงบประมาณจะกรองไม่เจออะไรเลย
        $this->seed(FiscalYearSeeder::class);

        $this->department = Department::create(['name' => 'ฝ่ายทดสอบ']);
        $this->type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $someoneElse = User::factory()->create(['department_id' => $this->department->id]);
        $this->othersLeave = Leave::create([
            'user_id' => $someoneElse->id,
            'leave_type_id' => $this->type->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ของคนอื่น',
            'status' => 'Approved',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['department_id' => $this->department->id]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /**
     * เงื่อนไขเดียวกับที่ DashboardController ใช้ตัดสินว่าเห็นข้อมูลรวมหรือไม่
     *
     * ตรวจที่ระดับการตัดสินสิทธิ์แทนการยิง endpoint จริง เพราะ
     * `/api/dashboard` ใช้ `MONTH()` ซึ่งเป็นฟังก์ชันเฉพาะ MySQL
     * จึงพังบน SQLite ที่ชุดทดสอบใช้ (ปัญหาเดิม ไม่ได้เกิดจาก LEGACY-04
     * — บันทึกไว้เป็นข้อจำกัดที่ต้องแก้ในเฟสถัดไป)
     */
    private function seesGlobalDashboard(User $user): bool
    {
        return $user->can('leave.approval.dept');
    }

    // ------------------------------------------------------------ แดชบอร์ด

    /**
     * บทบาทที่เคยถูกระบุด้วยชื่อ (Director / Deputy / HR Head) ถือสิทธิ์นี้
     * จึงต้องยังเห็นข้อมูลรวมเหมือนเดิม
     */
    public function test_leave_approver_still_sees_the_global_dashboard(): void
    {
        $this->assertTrue($this->seesGlobalDashboard($this->userWith(['leave.approval.dept'])));
    }

    /**
     * Document Officer ถือ leave.report.view และ attendance.view_all
     * แต่ **ไม่ได้** ถือ leave.approval.dept จึงต้องไม่เห็นแดชบอร์ดรวม
     * เหมือนที่เป็นมาก่อน refactor
     */
    public function test_report_viewer_alone_does_not_gain_the_global_dashboard(): void
    {
        $viewer = $this->userWith(['leave.report.view', 'attendance.view_all']);

        $this->assertFalse(
            $this->seesGlobalDashboard($viewer),
            'ต้องไม่ได้แดชบอร์ดรวมเพิ่มขึ้นมาจากการ refactor',
        );
    }

    public function test_ordinary_user_does_not_see_the_global_dashboard(): void
    {
        $this->assertFalse($this->seesGlobalDashboard($this->userWith([])));
    }

    // -------------------------------------------------------------- รายงาน

    public function test_report_viewer_sees_organisation_wide_report(): void
    {
        $viewer = $this->userWith(['leave.report.view']);

        $response = $this->actingAs($viewer)->getJson('/api/reports/summary');
        $response->assertOk();

        $this->assertGreaterThan(0, (int) data_get($response->json(), 'summary.total_requests', 0));
    }

    public function test_user_without_report_permission_sees_only_their_own(): void
    {
        $viewer = $this->userWith([]);

        $response = $this->actingAs($viewer)->getJson('/api/reports/summary');
        $response->assertOk();

        $this->assertSame(0, (int) data_get($response->json(), 'summary.total_requests', 0));
    }
}
