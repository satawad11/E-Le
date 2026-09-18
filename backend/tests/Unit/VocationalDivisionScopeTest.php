<?php

namespace Tests\Unit;

use App\Models\OrgUnit;
use App\Models\User;
use App\Services\OrgScopeResolver;
use App\Services\PermissionEvaluator;
use Database\Seeders\VocationalOrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VocationalDivisionScopeTest extends TestCase
{
    use RefreshDatabase;

    private OrgScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new OrgScopeResolver(app(PermissionEvaluator::class));
    }

    public function test_it_resolves_division_for_vocational_seeded_units(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $resourceDiv = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->firstOrFail();
        $academicDiv = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->firstOrFail();

        $hrUnit = OrgUnit::where('code', 'ORG-SEC-HR')->firstOrFail();
        $financeUnit = OrgUnit::where('code', 'ORG-SEC-FINANCE')->firstOrFail();
        $curriculumUnit = OrgUnit::where('code', 'ORG-SEC-CURRICULUM')->firstOrFail();

        // Create a department under Academic Division
        $autoDept = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'DEPT-AUTO',
            'name' => 'แผนกวิชาช่างยนต์',
            'type' => 'department',
            'parent_id' => $academicDiv->id,
            'effective_from' => '2026-01-01',
        ]);

        // Workshop under auto department (2 levels deep under division)
        $workshop = OrgUnit::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'SUB-WORKSHOP-1',
            'name' => 'งานเครื่องยนต์เล็ก',
            'type' => 'section',
            'parent_id' => $autoDept->id,
            'effective_from' => '2026-01-01',
        ]);

        // Assert division resolution
        $this->assertEquals($resourceDiv->id, $this->resolver->divisionOrgUnitId($hrUnit->id));
        $this->assertEquals($resourceDiv->id, $this->resolver->divisionOrgUnitId($financeUnit->id));
        $this->assertEquals($academicDiv->id, $this->resolver->divisionOrgUnitId($curriculumUnit->id));
        $this->assertEquals($academicDiv->id, $this->resolver->divisionOrgUnitId($autoDept->id));
        $this->assertEquals($academicDiv->id, $this->resolver->divisionOrgUnitId($workshop->id));

        // Assert same division logic
        $this->assertTrue($this->resolver->isSameDivision($hrUnit->id, $financeUnit->id));
        $this->assertTrue($this->resolver->isSameDivision($autoDept->id, $curriculumUnit->id));
        $this->assertTrue($this->resolver->isSameDivision($autoDept->id, $workshop->id));
        $this->assertFalse($this->resolver->isSameDivision($hrUnit->id, $autoDept->id));
    }

    public function test_it_resolves_same_division_for_users(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $academicDiv = OrgUnit::where('code', 'ORG-DIV-ACADEMIC')->firstOrFail();
        $resourceDiv = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->firstOrFail();

        $hrUnit = OrgUnit::where('code', 'ORG-SEC-HR')->firstOrFail();
        $financeUnit = OrgUnit::where('code', 'ORG-SEC-FINANCE')->firstOrFail();
        $curriculumUnit = OrgUnit::where('code', 'ORG-SEC-CURRICULUM')->firstOrFail();

        $hrOfficer = User::factory()->create(['org_unit_id' => $hrUnit->id]);
        $financeOfficer = User::factory()->create(['org_unit_id' => $financeUnit->id]);
        $academicTeacher = User::factory()->create(['org_unit_id' => $curriculumUnit->id]);

        $this->assertTrue($this->resolver->isSameDivision($hrOfficer, $financeOfficer));
        $this->assertFalse($this->resolver->isSameDivision($hrOfficer, $academicTeacher));
    }
}
