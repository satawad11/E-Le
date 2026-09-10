<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ตรวจความพร้อมของสายอนุมัติก่อนใบลาค้าง
 *
 * ตัวหาผู้อนุมัติเป็น fail-closed โดยเจตนา ผลที่ผู้ใช้เห็นเมื่อข้อมูลไม่ครบจึงเป็น
 * "ใบลาค้างเงียบ ๆ" ไม่ใช่ข้อความผิดพลาด หน้านี้ต้องบอกให้รู้ตั้งแต่ตอนตั้งค่า
 */
class ApprovalChainReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        OrgUnit::factory()->create(['name' => 'ทดสอบ']);

        $adminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo(
            Permission::firstOrCreate(['name' => 'manage_workflow_steps', 'guard_name' => 'web'])
        );

        $this->admin = User::factory()->create(['name' => 'ผู้ดูแล ระบบ']);
        $this->admin->assignRole($adminRole);
    }

    private function check(array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/workflow-steps/readiness', $payload);
    }

    public function test_บอกได้ว่าบทบาทที่เลือกยังไม่มีใครถือ(): void
    {
        $empty = Role::create(['name' => 'Director', 'guard_name' => 'web']);

        $response = $this->check([
            'steps' => [['name' => 'ผู้อำนวยการอนุมัติ', 'approver_type' => 'ROLE', 'role_id' => $empty->id]],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Director', $response->json('steps.0.config_problem'));

        // ไม่มีใครถือบทบาท = ทุกคนที่ยื่นจะติดขั้นนี้ ไม่ใช่แค่บางคน
        $this->assertSame($response->json('checked_users'), $response->json('steps.0.no_approver_count'));
        $this->assertSame($response->json('checked_users'), $response->json('blocked_users'));
    }

    public function test_บอกได้ว่าผู้ยื่นเป็นผู้อนุมัติของขั้นนั้นเสียเอง(): void
    {
        /*
         * กรณีที่เกิดจริงและเคยไม่มีอะไรเตือน: หัวหน้าฝ่ายลาเอง แล้วขั้นแรกของสาย
         * คือ "หัวหน้าฝ่าย" ซึ่งมีคนเดียว — ใบของเขาจะค้างที่ขั้นแรกตลอดไป
         */
        $role = Role::create(['name' => 'HR Head', 'guard_name' => 'web']);
        $head = User::factory()->create(['name' => 'สมปอง หัวหน้า']);
        $head->assignRole($role);

        $response = $this->check([
            'steps' => [['name' => 'หัวหน้าตรวจสอบ', 'approver_type' => 'ROLE', 'role_id' => $role->id]],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('steps.0.self_only_count'));
        $this->assertSame($head->id, $response->json('steps.0.self_only_sample.0.id'));

        $blocked = collect($response->json('blocked'))->firstWhere('id', $head->id);
        $this->assertSame('self_only', $blocked['problems'][0]['reason']);
    }

    public function test_ไม่ทักเรื่องหัวหน้าหน่วยงานเมื่อสายไม่ได้ใช้ผังองค์กร(): void
    {
        $role = Role::create(['name' => 'HR Head', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $response = $this->check([
            'steps' => [['name' => 'ตรวจสอบ', 'approver_type' => 'ROLE', 'role_id' => $role->id]],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data_gaps.units_without_manager.not_applicable'));
    }

    public function test_รายงานหน่วยงานที่ยังไม่มีหัวหน้าเมื่อสายใช้ผังองค์กร(): void
    {
        $unitId = DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => 'GRP-01', 'name' => 'กลุ่มบริหารงานบุคคล', 'type' => 'GROUP',
            'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        User::factory()->create(['org_unit_id' => $unitId]);

        $response = $this->check([
            'steps' => [['name' => 'หัวหน้าหน่วยงานพิจารณา', 'approver_type' => 'DEPARTMENT_HEAD']],
        ]);

        $response->assertOk();

        $units = collect($response->json('data_gaps.units_without_manager.sample'));
        $this->assertTrue($units->contains('id', $unitId), 'หน่วยงานที่ไม่มีหัวหน้าต้องถูกรายงาน');
    }

    public function test_ผู้ใช้ที่ยังไม่มีสังกัดหรือประเภทบุคลากรถูกรายงาน(): void
    {
        $orphan = User::factory()->create(['name' => 'สมหญิง ไร้สังกัด', 'org_unit_id' => null, 'personnel_type_id' => null]);

        $response = $this->check(['steps' => [['name' => 'ตรวจสอบ', 'approver_type' => 'HR_OFFICER']]]);

        $response->assertOk();
        $this->assertTrue(collect($response->json('data_gaps.without_org_unit.sample'))->contains('id', $orphan->id));
        $this->assertTrue(collect($response->json('data_gaps.without_personnel_type.sample'))->contains('id', $orphan->id));
    }

    public function test_ตรวจสายที่บันทึกไว้จริงได้เมื่อไม่ส่งขั้นตอนมา(): void
    {
        $role = Role::create(['name' => 'Director', 'guard_name' => 'web']);
        ApprovalStep::create([
            'chain' => ApprovalStep::CHAIN_LEAVE, 'step_order' => 1,
            'name' => 'ผู้อำนวยการอนุมัติ', 'approver_type' => 'ROLE', 'role_id' => $role->id,
        ]);

        $response = $this->check();

        $response->assertOk();
        $response->assertJsonPath('steps.0.name', 'ผู้อำนวยการอนุมัติ');
    }

    public function test_ผู้ที่ไม่มีสิทธิ์จัดการสายอนุมัติเรียกไม่ได้(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->postJson('/api/admin/workflow-steps/readiness')
            ->assertForbidden();
    }
}
