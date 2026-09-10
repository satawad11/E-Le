<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\User;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * มอบหมายผู้รับผิดชอบหน่วยงานจากหน้าจอได้
 *
 * `org_unit_managers` เป็นข้อมูลที่ `ApproverResolverService` ใช้ตัดสินว่าหัวหน้า
 * หน่วยงานของผู้ยื่นคือใคร แต่ไม่เคยมี API ให้กรอก ตารางจึงว่างเปล่าทุกการติดตั้ง
 * และขั้นอนุมัติแบบอิงผังองค์กรตกไปใช้ทางถอยตามชื่อบทบาทเสมอ — ทุกคนทั้งองค์กร
 * เดินไปหาผู้อนุมัติชุดเดียวกันไม่ว่าสังกัดหน่วยไหน
 */
class OrgUnitManagerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'manage_departments', 'guard_name' => 'web']));

        $this->admin = User::factory()->create(['name' => 'ผู้ดูแล ระบบ']);
        $this->admin->assignRole($role);
    }

    private function orgUnit(string $name, ?int $parentId = null): int
    {
        return DB::table('org_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'code' => Str::random(8), 'name' => $name, 'type' => 'department',
            'parent_id' => $parentId,
            'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_มอบหมายแล้วตัวหาผู้อนุมัติเห็นทันที(): void
    {
        $unit = $this->orgUnit('งานบุคลากร');
        $head = User::factory()->create(['name' => 'สมปอง หัวหน้างาน', 'org_unit_id' => $unit]);
        $staff = User::factory()->create(['name' => 'สมหญิง เจ้าหน้าที่', 'org_unit_id' => $unit]);

        $step = ['approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null];

        // ก่อนมอบหมาย ไม่มีใครเป็นหัวหน้าของหน่วยนี้
        $this->assertSame([], app(ApproverResolverService::class)->resolveEligibleApproverIds($staff, $step));

        $this->actingAs($this->admin)->postJson('/api/admin/org-unit-managers', [
            'org_unit_id' => $unit,
            'user_id' => $head->id,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame(
            [$head->id],
            app(ApproverResolverService::class)->resolveEligibleApproverIds($staff, $step),
        );
    }

    public function test_การมอบหมายที่ยังไม่ถึงวันมีผลยังไม่นับ(): void
    {
        $unit = $this->orgUnit('งานการเงิน');
        $head = User::factory()->create(['org_unit_id' => $unit]);
        $staff = User::factory()->create(['org_unit_id' => $unit]);

        $this->actingAs($this->admin)->postJson('/api/admin/org-unit-managers', [
            'org_unit_id' => $unit,
            'user_id' => $head->id,
            'effective_from' => now()->addMonth()->toDateString(),
        ])->assertCreated();

        $this->assertSame([], app(ApproverResolverService::class)->resolveEligibleApproverIds(
            $staff,
            ['approver_type' => ApprovalStep::RESOLVER_DEPARTMENT_HEAD, 'role_id' => null],
        ));
    }

    public function test_ปิดการมอบหมายเป็นการกำหนดวันสิ้นสุด_ไม่ใช่ลบประวัติ(): void
    {
        $unit = $this->orgUnit('งานพัสดุ');
        $head = User::factory()->create(['org_unit_id' => $unit]);

        $this->actingAs($this->admin)->postJson('/api/admin/org-unit-managers', [
            'org_unit_id' => $unit, 'user_id' => $head->id,
            'effective_from' => now()->subYear()->toDateString(),
        ])->assertCreated();

        $id = DB::table('org_unit_managers')->where('org_unit_id', $unit)->value('id');

        $this->actingAs($this->admin)
            ->putJson("/api/admin/org-unit-managers/{$id}/end", ['effective_to' => now()->toDateString()])
            ->assertOk();

        // แถวยังอยู่ — ประวัติคือหลักฐานว่าใบลาที่อนุมัติแล้วเดินผ่านผู้มีอำนาจจริง
        $row = DB::table('org_unit_managers')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame(now()->toDateString(), (string) $row->effective_to);
    }

    public function test_หน่วยงานที่ยังไม่มีผู้รับผิดชอบถูกชี้ให้เห็น(): void
    {
        $withHead = $this->orgUnit('งานที่มีหัวหน้า');
        $without = $this->orgUnit('งานที่ยังไม่มีหัวหน้า');
        $head = User::factory()->create(['org_unit_id' => $withHead]);

        $this->actingAs($this->admin)->postJson('/api/admin/org-unit-managers', [
            'org_unit_id' => $withHead, 'user_id' => $head->id,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $units = collect($this->actingAs($this->admin)->getJson('/api/admin/org-unit-managers/units')->json());

        $this->assertTrue($units->firstWhere('id', $withHead)['has_manager']);
        $this->assertFalse($units->firstWhere('id', $without)['has_manager']);
    }

    public function test_ผู้ใช้ที่ปิดบัญชีแล้วรับผิดชอบหน่วยงานไม่ได้(): void
    {
        $unit = $this->orgUnit('งานทดสอบ');
        $inactive = User::factory()->create(['org_unit_id' => $unit, 'is_active' => false]);

        $this->actingAs($this->admin)->postJson('/api/admin/org-unit-managers', [
            'org_unit_id' => $unit, 'user_id' => $inactive->id,
            'effective_from' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_ผู้ที่ไม่มีสิทธิ์จัดการหน่วยงานเรียกไม่ได้(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson('/api/admin/org-unit-managers')->assertForbidden();
    }
}
