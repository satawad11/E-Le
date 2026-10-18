<?php

namespace Tests\Legacy\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ตัวกรองหน่วยงานของหน้า "รายงานการลาราชการ" — เดิมเรียก /api/departments ที่ไม่มีอยู่จริง (404)
 * ทำให้ dropdown ว่างเสมอ endpoint ใหม่ต้องตรวจสิทธิ์ฝั่งเซิร์ฟเวอร์และเคารพขอบเขตหน่วยงาน
 */
class GovernmentLeaveReportDepartmentsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/reports/government-leave-departments';

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));

        return $user;
    }

    public function test_report_viewer_gets_departments_with_id_and_name_only(): void
    {
        $a = Department::create(['name' => 'แผนก ก']);
        $b = Department::create(['name' => 'แผนก ข']);

        $rows = $this->actingAs($this->userWith('leave.report.view'))->getJson(self::URL)->assertOk()->json('data');

        $ids = collect($rows)->pluck('id');
        $this->assertTrue($ids->contains($a->id) && $ids->contains($b->id));
        foreach ($rows as $row) {
            $this->assertSame(['id', 'name'], array_keys($row));
        }
    }

    public function test_leave_manager_gets_departments(): void
    {
        $dept = Department::create(['name' => 'แผนก ค']);

        $this->actingAs($this->userWith('leave.management.manage'))->getJson(self::URL)
            ->assertOk()->assertJsonFragment(['id' => $dept->id, 'name' => 'แผนก ค']);
    }

    public function test_user_without_report_permission_is_forbidden(): void
    {
        Department::create(['name' => 'แผนก ก']);

        $this->actingAs(User::factory()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_list_is_limited_to_the_viewers_org_scope(): void
    {
        $mine = Department::create(['name' => 'แผนก ของฉัน']);
        $other = Department::create(['name' => 'แผนก อื่น']);
        $viewer = $this->userWith('leave.report.view');
        $viewer->update(['department_id' => $mine->id]); // User::booted() sync org_unit_id ตามแผนก

        DB::table('permission_scopes')->insert([
            'model_type' => $viewer->getMorphClass(),
            'model_id' => $viewer->getKey(),
            'permission_name' => 'leave.report.view',
            'scope' => OrgScopeResolver::UNIT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = collect($this->actingAs($viewer)->getJson(self::URL)->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($other->id));
    }
}
