<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ACCESS-GOV-01 · A1 — `org:apply-structure` จัดฝ่าย/งานตามระเบียบบริหารสถานศึกษา พ.ศ. 2569
 */
class SchoolStructureCommandTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $root;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = OrgUnit::where('code', 'ORG-ROOT')->firstOrFail();
        $this->admin = User::factory()->create(['email' => 'admin@college.test', 'is_active' => true]);
        $this->admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    }

    private function unit(string $name, string $type = 'department'): OrgUnit
    {
        return OrgUnit::create([
            'uuid' => (string) Str::uuid(), 'parent_id' => $this->root->id, 'code' => 'T-'.Str::upper(Str::random(8)),
            'name' => $name, 'type' => $type, 'effective_from' => '2020-01-01', 'is_active' => true,
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = OrgUnit::count();

        $this->artisan('org:apply-structure', ['--by' => 'admin@college.test', '--dry-run' => true])
            ->expectsOutputToContain('[ทดลอง ยังไม่บันทึก]')
            ->assertSuccessful();

        $this->assertSame($before, OrgUnit::count());
    }

    public function test_builds_divisions_and_sections_moves_existing_units_and_is_idempotent(): void
    {
        $supplies = $this->unit('งานพัสดุ', 'section');
        $automotive = $this->unit('แผนกวิชาช่างยนต์');
        $legacy = $this->unit('ฝ่ายสารสนเทศ');

        $this->artisan('org:apply-structure', ['--by' => 'admin@college.test'])->assertSuccessful();

        $divisions = OrgUnit::where('parent_id', $this->root->id)->where('type', 'division')->pluck('name')->sort()->values()->all();
        $this->assertSame(collect(['ฝ่ายบริหารทรัพยากร', 'ฝ่ายยุทธศาสตร์และแผนงาน', 'ฝ่ายกิจการนักเรียน นักศึกษา', 'ฝ่ายวิชาการ'])->sort()->values()->all(), $divisions);

        $resources = OrgUnit::where('name', 'ฝ่ายบริหารทรัพยากร')->firstOrFail();
        $academic = OrgUnit::where('name', 'ฝ่ายวิชาการ')->firstOrFail();
        $this->assertSame($resources->id, $supplies->fresh()->parent_id);
        $this->assertSame($academic->id, $automotive->fresh()->parent_id);
        // หน่วยที่ไม่อยู่ในระเบียบไม่ถูกแตะ
        $this->assertSame($this->root->id, $legacy->fresh()->parent_id);
        $this->assertTrue($legacy->fresh()->is_active);

        // 7 + 6 + 5 + 5 งาน (ไม่รวมงานเฉพาะปริญญาตรี) และงานพัสดุไม่ถูกสร้างซ้ำ
        $this->assertSame(23, OrgUnit::where('type', 'section')->whereIn('parent_id', OrgUnit::where('type', 'division')->pluck('id'))->count());
        $this->assertSame(1, OrgUnit::where('name', 'งานพัสดุ')->count());
        $this->assertSame(0, OrgUnit::where('name', 'งานพัฒนาหลักสูตรสายเทคโนโลยีหรือสายปฏิบัติการ')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'org_unit.created', 'actor_id' => $this->admin->id]);

        $count = OrgUnit::count();
        $this->artisan('org:apply-structure', ['--by' => 'admin@college.test'])->assertSuccessful();
        $this->assertSame($count, OrgUnit::count());
    }

    public function test_requires_the_structure_permission(): void
    {
        User::factory()->create(['email' => 'teacher@college.test', 'is_active' => true]);
        $before = OrgUnit::count();

        $this->artisan('org:apply-structure', ['--by' => 'teacher@college.test'])->assertFailed();

        $this->assertSame($before, OrgUnit::count());
    }
}
