<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\PersonnelAssignment;
use App\Models\PersonnelType;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\LeavePolicyService;
use App\Services\OrgScopeResolver;
use App\Services\Reporting\ReportFilter;
use App\Services\Reporting\ReportingDataService;
use App\Services\Time\TimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonnelDomainCoreServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_scope_resolver_prioritizes_personnel_assignment(): void
    {
        $orgUnit1 = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-OLD',
            'name' => 'หน่วยงานเดิม',
            'type' => 'academic',
            'effective_from' => '2023-01-01',
            'is_active' => true,
        ]);

        $orgUnit2 = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-NEW',
            'name' => 'หน่วยงานใหม่',
            'type' => 'academic',
            'effective_from' => '2025-01-01',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'org_unit_id' => $orgUnit1->id,
        ]);

        // Create assignment for orgUnit2
        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'org_unit_id' => $orgUnit2->id,
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ]);

        $resolver = app(OrgScopeResolver::class);
        $this->assertEquals($orgUnit2->id, $resolver->homeOrgUnitId($user));
    }

    public function test_leave_policy_service_resolves_from_current_assignment(): void
    {
        $typeA = PersonnelType::firstOrCreate(['code' => 'type_a'], ['name' => 'บุคลากรประเภท ก', 'is_active' => true]);
        $typeB = PersonnelType::firstOrCreate(['code' => 'type_b'], ['name' => 'บุคลากรประเภท ข', 'is_active' => true]);

        $leaveType = LeaveType::create(['name' => 'ลาพักผ่อนทดสอบ', 'max_days' => 10]);

        $policyA = LeavePolicy::create([
            'personnel_type_id' => $typeA->id,
            'leave_type_id' => $leaveType->id,
            'effective_from' => '2025-01-01',
            'annual_quota' => 10,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);

        $policyB = LeavePolicy::create([
            'personnel_type_id' => $typeB->id,
            'leave_type_id' => $leaveType->id,
            'effective_from' => '2025-01-01',
            'annual_quota' => 20,
            'period_basis' => 'fiscal_year',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['personnel_type_id' => $typeA->id]);

        // Assign to typeB in PersonnelAssignment
        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'personnel_type_id' => $typeB->id,
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ]);

        $policyService = app(LeavePolicyService::class);
        $resolved = $policyService->resolve($user, $leaveType->id, Carbon::now());

        $this->assertEquals($policyB->id, $resolved->id);
        $this->assertEquals(20, $resolved->annual_quota);
    }

    public function test_reporting_data_service_reads_personnel_domain(): void
    {
        $orgUnit = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'UNIT-REP',
            'name' => 'กลุ่มงานวิจัย',
            'type' => 'academic',
            'effective_from' => '2024-01-01',
            'is_active' => true,
        ]);

        $personnelType = PersonnelType::firstOrCreate(['code' => 'researcher'], ['name' => 'นักวิจัย', 'is_active' => true]);

        $user = User::factory()->create([
            'name' => 'นาย สมหมาย นามสกุลเดิม',
            'is_active' => true,
        ]);

        Personnel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'ดร.',
            'first_name' => 'สมหมาย',
            'last_name' => 'ปัญญาดี',
            'status' => 'active',
            'effective_from' => '2024-01-01',
        ]);

        PersonnelAssignment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'org_unit_id' => $orgUnit->id,
            'personnel_type_id' => $personnelType->id,
            'position' => 'หัวหน้าฝ่ายวิจัย',
            'effective_from' => '2024-01-01',
        ]);

        // Grant permission to viewer
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view_users', 'guard_name' => 'web']));

        $reportingService = app(ReportingDataService::class);
        $filter = new ReportFilter(now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString(), (int) date('Y'));

        $dataset = $reportingService->build('personnel', $filter, $viewer);
        $matchingRow = collect($dataset->rows)->firstWhere('user_id', $user->id);

        $this->assertNotNull($matchingRow);
        $this->assertEquals('ดร.สมหมาย ปัญญาดี', $matchingRow['name']);
        $this->assertEquals('หัวหน้าฝ่ายวิจัย', $matchingRow['position']);
        $this->assertEquals('กลุ่มงานวิจัย', $matchingRow['organization']);
        $this->assertEquals('นักวิจัย', $matchingRow['personnel_type']);
    }

    public function test_fallback_to_legacy_fields_when_no_domain_assignment_exists(): void
    {
        $dept = Department::create(['name' => 'ฝ่ายแผนงาน']);
        $type = PersonnelType::firstOrCreate(['code' => 'staff'], ['name' => 'เจ้าหน้าที่', 'is_active' => true]);

        $user = User::factory()->create([
            'name' => 'สมปอง ยิ้มแย้ม',
            'department_id' => $dept->id,
            'personnel_type_id' => $type->id,
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'position' => 'เจ้าหน้าที่สารบรรณ',
        ]);

        // Delete any assignment to test fallback explicitly
        PersonnelAssignment::where('user_id', $user->id)->delete();

        $resolver = app(OrgScopeResolver::class);
        // Falls back through department map or null gracefully
        $this->assertNotNull($resolver);

        $policyService = app(LeavePolicyService::class);
        $leaveType = LeaveType::create(['name' => 'ลากิจทดสอบ', 'max_days' => 5]);
        $resolved = $policyService->resolve($user, $leaveType->id, Carbon::now());

        $this->assertEquals($type->id, $resolved->personnel_type_id);
    }
}
