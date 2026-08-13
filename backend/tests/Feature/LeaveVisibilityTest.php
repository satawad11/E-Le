<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-4 — ล็อกพฤติกรรมของ "ใครเห็นใบลาของใคร" ก่อนถอด hasRole ออก
 *
 * เขียนก่อนแก้คอนโทรลเลอร์ เพื่อพิสูจน์ว่าการเลิก hard-code ชื่อ role
 * ไม่เปลี่ยนสิ่งที่ผู้ใช้เห็นแม้แต่รายเดียว
 *
 * บริบทสำคัญ: role `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย` ที่โค้ดเดิมใช้หรี่ขอบเขต
 * **ไม่มีอยู่จริงในฐานข้อมูล** ตรรกะนั้นจึงไม่เคยทำงาน ผู้ที่ผ่านด่านแรก
 * จึงเห็นใบลาทั้งองค์กรเสมอ — เทสต์ชุดนี้ล็อกความจริงข้อนั้นไว้
 */
class LeaveVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;

    private Department $deptB;

    private LeaveType $type;

    private Leave $leaveInA;

    private Leave $leaveInB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'leave.management.manage', 'leave.approval.hr', 'leave.approval.final',
            'leave.report.view', 'leave.approval.dept',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->deptA = Department::create(['name' => 'ฝ่าย ก']);
        $this->deptB = Department::create(['name' => 'ฝ่าย ข']);
        $this->type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $this->leaveInA = $this->leaveFor($this->deptA);
        $this->leaveInB = $this->leaveFor($this->deptB);
    }

    private function leaveFor(Department $department): Leave
    {
        $owner = User::factory()->create(['department_id' => $department->id]);

        return Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $this->type->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบ',
            'status' => 'Pending',
        ]);
    }

    private function viewer(Department $department, array $permissions): User
    {
        $user = User::factory()->create(['department_id' => $department->id]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function listedLeaveIds(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/leaves');
        $response->assertOk();

        $payload = $response->json();
        $rows = $payload['data'] ?? $payload;

        return array_map(fn ($row) => $row['id'], $rows);
    }

    // ---------------------------------------- พฤติกรรมที่ต้องคงไว้ทุกประการ

    public function test_ordinary_user_sees_only_their_own_leaves(): void
    {
        $user = $this->viewer($this->deptA, []);

        $this->assertSame([], $this->listedLeaveIds($user));
    }

    public function test_report_viewer_sees_every_department(): void
    {
        $viewer = $this->viewer($this->deptA, ['leave.report.view']);

        $ids = $this->listedLeaveIds($viewer);

        $this->assertContains($this->leaveInA->id, $ids);
        $this->assertContains($this->leaveInB->id, $ids, 'ผู้มีสิทธิ์รายงานต้องเห็นข้ามฝ่ายเหมือนเดิม');
    }

    /**
     * จุดสำคัญที่สุด: วันนี้ผู้ถือ leave.approval.dept เห็นทั้งองค์กร
     * เพราะตรรกะหรี่ขอบเขตผูกกับ role ที่ไม่มีอยู่จริง
     * การถอด hasRole ออกต้องไม่ทำให้เขาเห็นแคบลง
     */
    public function test_department_approver_still_sees_every_department_by_default(): void
    {
        $viewer = $this->viewer($this->deptA, ['leave.approval.dept']);

        $ids = $this->listedLeaveIds($viewer);

        $this->assertContains($this->leaveInA->id, $ids);
        $this->assertContains($this->leaveInB->id, $ids, 'ค่าเริ่มต้นต้องเป็น ORGANIZATION เท่าพฤติกรรมเดิม');
    }

    /**
     * ล็อกความจริงที่ค้นพบตอนเขียนเทสต์: ผู้ถือ `leave.approval.hr` เพียงอย่างเดียว
     * **ไม่** เห็นรายการใบลา เพราะเงื่อนไข $canViewAll เดิมไม่ได้รวมสิทธิ์นี้ไว้
     * (เขาเข้าถึงผ่านหน้า governance คนละเส้นทาง)
     *
     * เทสต์นี้กันไม่ให้ใครเผลอเพิ่มสิทธิ์ระหว่าง refactor
     * ถ้าจะเปลี่ยนต้องเป็นการตัดสินใจที่ตั้งใจ ไม่ใช่ผลข้างเคียง
     */
    public function test_hr_permission_alone_does_not_grant_the_leave_list(): void
    {
        $viewer = $this->viewer($this->deptA, ['leave.approval.hr']);

        $ids = $this->listedLeaveIds($viewer);

        $this->assertNotContains($this->leaveInA->id, $ids);
        $this->assertNotContains($this->leaveInB->id, $ids);
    }

    // ------------------------------------ ความสามารถใหม่: หรี่ขอบเขตได้ต่อคน

    public function test_admin_can_narrow_a_viewer_to_their_own_unit(): void
    {
        $viewer = $this->viewer($this->deptA, ['leave.approval.dept']);

        DB::table('permission_scopes')->insert([
            'model_type' => $viewer->getMorphClass(),
            'model_id' => $viewer->getKey(),
            'permission_name' => 'leave.approval.dept',
            'scope' => OrgScopeResolver::UNIT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = $this->listedLeaveIds($viewer->fresh());

        $this->assertContains($this->leaveInA->id, $ids, 'ยังต้องเห็นใบลาในฝ่ายตน');
        $this->assertNotContains($this->leaveInB->id, $ids, 'ต้องไม่เห็นใบลาข้ามฝ่ายเมื่อถูกหรี่เป็น UNIT');
    }

    public function test_narrowing_does_not_hide_the_users_own_leave(): void
    {
        $viewer = $this->viewer($this->deptA, ['leave.approval.dept']);

        $own = Leave::create([
            'user_id' => $viewer->id,
            'leave_type_id' => $this->type->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'total_days' => 2,
            'reason' => 'ของตัวเอง',
            'status' => 'Pending',
        ]);

        DB::table('permission_scopes')->insert([
            'model_type' => $viewer->getMorphClass(),
            'model_id' => $viewer->getKey(),
            'permission_name' => 'leave.approval.dept',
            'scope' => OrgScopeResolver::SELF,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = $this->listedLeaveIds($viewer->fresh());

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($this->leaveInB->id, $ids);
    }
}
