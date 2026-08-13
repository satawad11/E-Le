<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\OrgScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEGACY-04 · 04-3 — ขอบเขตสิทธิ์ผูกกับต้นไม้ org_units
 *
 * สิ่งที่ต้องพิสูจน์:
 *  1. ไม่กำหนดขอบเขต = UNIT = ผลเท่าโค้ดเดิมทุกประการ
 *  2. DESCENDANTS มองเห็นหน่วยงานลูกได้ ซึ่งเดิมทำไม่ได้
 *  3. ORGANIZATION เห็นทั้งหมด, SELF ไม่เห็นหน่วยงานใดเลย
 */
class OrgScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private OrgScopeResolver $resolver;

    private int $faculty;      // ฝ่ายวิชาการ (แม่)

    private int $deptA;        // แผนกใต้ฝ่ายวิชาการ

    private int $deptB;        // แผนกใต้ฝ่ายวิชาการ อีกแผนก

    private int $unrelated;    // หน่วยงานคนละสาย

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('leave.approval.dept', 'web');
        $this->resolver = app(OrgScopeResolver::class);

        $root = $this->orgUnit('ROOT', 'องค์กร', null, 'organization');
        $this->faculty = $this->orgUnit('FAC', 'ฝ่ายวิชาการ', $root, 'faculty');
        $this->deptA = $this->orgUnit('A', 'แผนก ก', $this->faculty, 'department');
        $this->deptB = $this->orgUnit('B', 'แผนก ข', $this->faculty, 'department');
        $this->unrelated = $this->orgUnit('X', 'ฝ่ายอื่น', $root, 'department');
    }

    private function orgUnit(string $code, string $name, ?int $parent, string $type): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userIn(int $orgUnitId): User
    {
        return User::factory()->create(['org_unit_id' => $orgUnitId])->fresh();
    }

    private function setScope(User $user, string $scope): void
    {
        DB::table('permission_scopes')->updateOrInsert(
            [
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
                'permission_name' => 'leave.approval.dept',
            ],
            ['scope' => $scope, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    // ------------------------------------------------- ค่าเริ่มต้น = พฤติกรรมเดิม

    public function test_default_scope_is_unit_and_sees_only_its_own_unit(): void
    {
        $user = $this->userIn($this->deptA);

        $this->assertSame(['scope' => 'UNIT', 'org_unit_id' => null], $this->resolver->resolve($user, 'leave.approval.dept'));
        $this->assertSame([$this->deptA], $this->resolver->visibleOrgUnitIds($user, 'leave.approval.dept'));
        $this->assertTrue($this->resolver->canSeeOrgUnit($user, 'leave.approval.dept', $this->deptA));
        $this->assertFalse($this->resolver->canSeeOrgUnit($user, 'leave.approval.dept', $this->deptB));
    }

    // ------------------------------------------------------------ DESCENDANTS

    public function test_descendants_scope_sees_child_units(): void
    {
        $head = $this->userIn($this->faculty);
        $this->setScope($head, OrgScopeResolver::DESCENDANTS);

        $visible = $this->resolver->visibleOrgUnitIds($head, 'leave.approval.dept');

        $this->assertContains($this->faculty, $visible);
        $this->assertContains($this->deptA, $visible);
        $this->assertContains($this->deptB, $visible);
        $this->assertNotContains($this->unrelated, $visible);
    }

    public function test_descendants_scope_still_excludes_other_branches(): void
    {
        $head = $this->userIn($this->faculty);
        $this->setScope($head, OrgScopeResolver::DESCENDANTS);

        $this->assertFalse($this->resolver->canSeeOrgUnit($head, 'leave.approval.dept', $this->unrelated));
    }

    // ------------------------------------------------- ORGANIZATION และ SELF

    public function test_organization_scope_is_unrestricted(): void
    {
        $user = $this->userIn($this->deptA);
        $this->setScope($user, OrgScopeResolver::ORGANIZATION);

        $this->assertNull($this->resolver->visibleOrgUnitIds($user, 'leave.approval.dept'));
        $this->assertTrue($this->resolver->canSeeOrgUnit($user, 'leave.approval.dept', $this->unrelated));
    }

    public function test_self_scope_sees_no_unit_at_all(): void
    {
        $user = $this->userIn($this->deptA);
        $this->setScope($user, OrgScopeResolver::SELF);

        $this->assertSame([], $this->resolver->visibleOrgUnitIds($user, 'leave.approval.dept'));
        $this->assertFalse($this->resolver->canSeeOrgUnit($user, 'leave.approval.dept', $this->deptA));
    }

    // ------------------------------------------------------- ผลจริงกับ LeavePolicy

    public function test_leave_policy_uses_the_scope_for_department_approvers(): void
    {
        /*
         * ใช้เส้นทางจริงของระบบ: สร้าง Department แล้วปล่อยให้ booted() สร้าง
         * org_unit ให้เอง จากนั้นย้ายหน่วยงานนั้นไปอยู่ใต้ฝ่ายวิชาการ
         *
         * ห้ามกำหนด org_unit_id ให้ผู้ใช้เองพร้อมกับ department_id เพราะ
         * User::booted() จะ sync org_unit_id ตาม department_id ทับให้เสมอ
         * (พฤติกรรมที่ถูกต้องของ LEGACY-03 — เคยทำให้เทสต์ฉบับแรกของผมผิด)
         */
        $department = Department::create(['name' => 'แผนก ก']);
        $departmentUnitId = (int) DB::table('department_org_unit_map')
            ->where('department_id', $department->id)
            ->value('org_unit_id');

        DB::table('org_units')->where('id', $departmentUnitId)->update(['parent_id' => $this->faculty]);

        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $owner = User::factory()->create(['department_id' => $department->id]);
        $leave = Leave::create([
            'user_id' => $owner->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'ทดสอบขอบเขต',
            'status' => 'Pending',
        ]);

        // หัวหน้าฝ่าย (หน่วยงานแม่) ขอบเขต UNIT -> ยังไม่เห็นใบลาในแผนกลูก
        $head = $this->userIn($this->faculty);
        $head->givePermissionTo('leave.approval.dept');
        $this->assertFalse($head->fresh()->can('view', $leave));

        // ขยายเป็น DESCENDANTS -> เห็นได้
        $this->setScope($head, OrgScopeResolver::DESCENDANTS);
        $this->assertTrue($head->fresh()->can('view', $leave));
    }
}
