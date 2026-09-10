<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrgUnit;
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

    // ------------------------------- Decision 3 — หัวหน้าหน่วยงานเป็น "การมอบหมาย"

    private function assignManager(
        User $user,
        int $orgUnitId,
        string $scope = OrgScopeResolver::UNIT,
        string $type = 'MANAGER',
        ?string $from = null,
        ?string $to = null,
    ): void {
        DB::table('org_unit_managers')->insert([
            'uuid' => (string) Str::uuid(),
            'org_unit_id' => $orgUnitId,
            'user_id' => $user->getKey(),
            'assignment_type' => $type,
            'scope' => $scope,
            'effective_from' => $from ?? now()->subDay()->toDateString(),
            'effective_to' => $to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ไม่มีการมอบหมาย = ไม่มีอะไรเปลี่ยน — เป็นเหตุผลที่ตารางนี้ปลอดภัยที่จะเพิ่ม
     */
    public function test_without_any_assignment_nothing_changes(): void
    {
        $user = $this->userIn($this->deptA);

        $this->assertSame([], $this->resolver->managedOrgUnitIds($user));
        $this->assertSame([$this->deptA], $this->resolver->visibleOrgUnitIds($user, 'leave.approval.dept'));
    }

    /**
     * หัวหน้าหน่วยงานเห็นหน่วยงานที่ตนรับผิดชอบ ไม่ใช่หน่วยงานต้นสังกัดของตน
     *
     * จุดสำคัญของ Decision 3: ตำแหน่งหัวหน้าไม่ใช่ชื่อ role แต่เป็นข้อมูลการมอบหมาย
     */
    public function test_a_manager_sees_the_unit_they_are_assigned_to(): void
    {
        $manager = $this->userIn($this->unrelated);
        $this->assignManager($manager, $this->deptA);

        $this->assertSame([$this->deptA], $this->resolver->visibleOrgUnitIds($manager, 'leave.approval.dept'));
        $this->assertTrue($this->resolver->canSeeOrgUnit($manager, 'leave.approval.dept', $this->deptA));
        $this->assertFalse($this->resolver->canSeeOrgUnit($manager, 'leave.approval.dept', $this->deptB));
    }

    /**
     * หน่วยงานที่มีหน่วยงานลูก ให้ขอบเขต DESCENDANTS ตาม Decision 3
     */
    public function test_a_manager_of_a_parent_unit_can_be_given_the_whole_subtree(): void
    {
        $manager = $this->userIn($this->unrelated);
        $this->assignManager($manager, $this->faculty, OrgScopeResolver::DESCENDANTS);

        $visible = $this->resolver->visibleOrgUnitIds($manager, 'leave.approval.dept');

        $this->assertContains($this->faculty, $visible);
        $this->assertContains($this->deptA, $visible);
        $this->assertContains($this->deptB, $visible);
        $this->assertNotContains($this->unrelated, $visible);
    }

    /**
     * ผู้รักษาการได้ขอบเขตเท่าหัวหน้าตัวจริงระหว่างช่วงที่รักษาการอยู่
     */
    public function test_an_acting_manager_is_treated_the_same_while_the_assignment_is_effective(): void
    {
        $acting = $this->userIn($this->unrelated);
        $this->assignManager($acting, $this->deptB, OrgScopeResolver::UNIT, 'ACTING');

        $this->assertSame([$this->deptB], $this->resolver->visibleOrgUnitIds($acting, 'leave.approval.dept'));
    }

    /**
     * พ้นหน้าที่แล้วต้องไม่เห็นข้อมูลของหน่วยงานเดิมอีก
     *
     * เก็บแถวเดิมไว้ตามข้อห้าม "overwrite ประวัติ effective-dated" ของโปรเจกต์
     */
    public function test_an_expired_assignment_grants_nothing(): void
    {
        $formerHead = $this->userIn($this->unrelated);
        $this->assignManager(
            $formerHead,
            $this->deptA,
            OrgScopeResolver::UNIT,
            'MANAGER',
            now()->subYear()->toDateString(),
            now()->subMonth()->toDateString(),
        );

        $this->assertSame([], $this->resolver->managedOrgUnitIds($formerHead));
        $this->assertFalse($this->resolver->canSeeOrgUnit($formerHead, 'leave.approval.dept', $this->deptA));
    }

    public function test_a_future_assignment_grants_nothing_yet(): void
    {
        $incoming = $this->userIn($this->unrelated);
        $this->assignManager(
            $incoming,
            $this->deptA,
            OrgScopeResolver::UNIT,
            'MANAGER',
            now()->addMonth()->toDateString(),
        );

        $this->assertSame([], $this->resolver->managedOrgUnitIds($incoming));
    }

    /**
     * ขอบเขตที่ผู้ดูแลกำหนดไว้เองต้องชนะการมอบหมายเสมอ
     * เพื่อให้หรี่ขอบเขตของหัวหน้าคนใดคนหนึ่งได้โดยไม่ต้องถอนการมอบหมาย
     */
    public function test_an_explicit_scope_row_still_wins_over_the_assignment(): void
    {
        $manager = $this->userIn($this->unrelated);
        $this->assignManager($manager, $this->faculty, OrgScopeResolver::DESCENDANTS);
        $this->setScope($manager, OrgScopeResolver::SELF);

        $this->assertSame([], $this->resolver->visibleOrgUnitIds($manager, 'leave.approval.dept'));
    }

    // ------------------------------------------------------- ผลจริงกับ LeavePolicy

    public function test_leave_policy_uses_the_scope_for_department_approvers(): void
    {
        // แผนกเป็นหน่วยงานในผังจริง แขวนไว้ใต้ฝ่ายวิชาการ
        $department = OrgUnit::factory()->create(['name' => 'แผนก ก', 'parent_id' => $this->faculty]);

        $type = LeaveType::create(['name' => 'ลาป่วย', 'max_days' => 30]);

        $owner = User::factory()->create(['org_unit_id' => $department->id]);
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
