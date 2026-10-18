<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\OrgUnit;
use App\Models\PersonnelAssignment;
use App\Models\User;
use App\Services\Workflow\ApproverResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * REDESIGN-2569 เฟส 3 · D-01 — สายอนุมัติมาตรฐาน: งานบุคลากร → หัวหน้างาน/แผนก → รองผู้อำนวยการ → ผู้อำนวยการ
 */
class StandardLeaveChainCommandTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;

    private OrgUnit $division;

    private OrgUnit $unit;

    private User $director;

    private User $deputy;

    private User $head;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // แยกสถานการณ์ทดสอบออกจากข้อมูลตั้งต้นของ migration
        User::query()->update(['is_active' => false]);
        OrgUnit::query()->update(['is_active' => false]);

        $this->root = OrgUnit::whereNull('parent_id')->first() ?? $this->unit('ROOT', 'วิทยาลัยทดสอบ', null);
        $this->root->update(['is_active' => true]);
        $this->division = $this->unit('DIV-RES', 'ฝ่ายบริหารทรัพยากร', $this->root->id);
        $this->unit = $this->unit('UNIT-FIN', 'งานการเงิน', $this->division->id);

        $this->director = $this->person($this->root);
        $this->deputy = $this->person($this->division);
        $this->head = $this->person($this->unit);
        $this->staff = $this->person($this->unit);

        $this->manage($this->root, $this->director);
        $this->manage($this->division, $this->deputy);

        // ขั้นที่ 1 หาเจ้าหน้าที่งานบุคลากรจากสิทธิ์ leave.management.manage
        $hr = $this->person($this->unit);
        $hr->givePermissionTo(Permission::findOrCreate('leave.management.manage', 'web'));
    }

    private function unit(string $code, string $name, ?int $parentId): OrgUnit
    {
        return OrgUnit::create(['uuid' => (string) Str::uuid(), 'code' => $code, 'name' => $name, 'type' => 'unit',
            'parent_id' => $parentId, 'effective_from' => '2025-01-01', 'is_active' => true]);
    }

    private function person(OrgUnit $unit): User
    {
        $user = User::factory()->create(['is_active' => true]);
        PersonnelAssignment::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'org_unit_id' => $unit->id, 'effective_from' => '2025-01-01']);

        return $user;
    }

    private function manage(OrgUnit $unit, User $user): void
    {
        DB::table('org_unit_managers')->insert(['uuid' => (string) Str::uuid(), 'org_unit_id' => $unit->id, 'user_id' => $user->id,
            'assignment_type' => 'MANAGER', 'scope' => 'UNIT', 'effective_from' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_check_fails_and_apply_refuses_while_a_unit_has_no_head(): void
    {
        $before = ApprovalStep::chain('leave')->pluck('id')->all();

        $this->artisan('leave:apply-standard-chain --check')
            ->expectsOutputToContain('ต้องแก้ก่อนใช้สายนี้')
            ->assertFailed();
        $this->artisan('leave:apply-standard-chain')->assertFailed();

        $this->assertSame($before, ApprovalStep::chain('leave')->pluck('id')->all(), 'ต้องไม่เปลี่ยนสายเมื่อยังมีปัญหา');
    }

    public function test_apply_writes_the_standard_chain_once_every_unit_has_a_head(): void
    {
        $this->manage($this->unit, $this->head);

        $this->artisan('leave:apply-standard-chain')->assertSuccessful();

        $steps = ApprovalStep::chain('leave')->orderBy('step_order')->get();
        $this->assertSame(
            [ApprovalStep::RESOLVER_HR_OFFICER, ApprovalStep::RESOLVER_DEPARTMENT_HEAD, ApprovalStep::RESOLVER_DIVISION_DIRECTOR, ApprovalStep::RESOLVER_DIRECTOR],
            $steps->pluck('approver_type')->all(),
        );
        $this->assertTrue(AuditLog::where('action', 'approval_chain.standard_applied')->exists());

        // ผู้อนุมัติแต่ละขั้นของบุคลากรงานการเงินมาจากผังหน่วยงาน
        $resolver = app(ApproverResolverService::class);
        $this->assertSame([$this->head->id], $resolver->resolveEligibleApproverIds($this->staff, $steps[1]));
        $this->assertSame([$this->deputy->id], $resolver->resolveEligibleApproverIds($this->staff, $steps[2]));
        $this->assertSame([$this->director->id], $resolver->resolveEligibleApproverIds($this->staff, $steps[3]));
    }

    public function test_check_warns_about_the_director_approving_own_leave_without_blocking(): void
    {
        $this->manage($this->unit, $this->head);

        $this->artisan('leave:apply-standard-chain --check')
            ->expectsOutputToContain('ข้อสังเกต')
            ->assertSuccessful();
    }

    public function test_check_fails_when_no_hr_officer_can_check(): void
    {
        $this->manage($this->unit, $this->head);
        DB::table('model_has_permissions')->delete();

        $this->artisan('leave:apply-standard-chain --check')
            ->expectsOutputToContain('หาผู้อนุมัติไม่พบ')
            ->assertFailed();
    }

    public function test_force_applies_even_with_missing_heads(): void
    {
        $this->artisan('leave:apply-standard-chain --force')->assertSuccessful();

        $this->assertSame(4, ApprovalStep::chain('leave')->count());
    }
}
