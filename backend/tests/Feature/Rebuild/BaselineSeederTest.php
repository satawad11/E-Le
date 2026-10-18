<?php

namespace Tests\Feature\Rebuild;

use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\PersonnelType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LeaveRegulationSeeder;
use Database\Seeders\OrganizationBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R02-9 / R02-12 — `migrate:fresh --seed` ได้ข้อมูลตั้งต้นตามแบบใหม่ และรันซ้ำได้
 */
class BaselineSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_org_unit_types_cover_levels_one_to_four(): void
    {
        $this->assertSame(
            ['department' => 3, 'division' => 2, 'institution' => 1, 'program' => 4, 'section' => 3, 'subsection' => 4],
            OrgUnitType::orderBy('code')->pluck('level', 'code')->all(),
        );
    }

    public function test_root_and_four_divisions_with_materialized_path(): void
    {
        $root = OrgUnit::where('code', OrganizationBaselineSeeder::ROOT['code'])->firstOrFail();

        $this->assertNull($root->parent_id);
        $this->assertSame('institution', $root->type->code);
        $this->assertSame('/'.$root->id.'/', $root->path);
        $this->assertSame(0, $root->depth);

        $divisions = OrgUnit::where('parent_id', $root->id)->orderBy('sort_order')->get();
        $this->assertCount(4, $divisions);

        foreach ($divisions as $division) {
            $this->assertSame('division', $division->type->code);
            $this->assertSame($root->path.$division->id.'/', $division->path);
            $this->assertSame(1, $division->depth);
        }

        $this->assertSame(5, OrgUnit::count(), 'baseline ไม่สร้างหน่วยระดับงาน/แผนก (นำเข้าใน R-03)');
    }

    public function test_personnel_types_follow_core_02(): void
    {
        $this->assertSame(8, PersonnelType::count());
        $this->assertTrue(PersonnelType::where('code', 'civil_teacher')->value('has_license'));
    }

    public function test_leave_regulation_is_seeded_for_every_personnel_type(): void
    {
        $leaveTypes = DB::table('leave_types')->count();
        $this->assertSame(12, $leaveTypes);

        foreach (array_merge(...array_values(LeaveRegulationSeeder::LEGACY_TYPE_MAP)) as $code) {
            $typeId = PersonnelType::where('code', $code)->value('id');
            $this->assertSame($leaveTypes, DB::table('leave_policies')->where('personnel_type_id', $typeId)->count(), "ประเภท {$code} ต้องมีนโยบายครบทุกประเภทการลา");
        }

        $sick = DB::table('leave_policies')
            ->join('leave_types', 'leave_types.id', '=', 'leave_policies.leave_type_id')
            ->join('personnel_types', 'personnel_types.id', '=', 'leave_policies.personnel_type_id')
            ->where('leave_types.name', 'ลาป่วย')->where('personnel_types.code', 'civil_teacher')
            ->first(['annual_quota', 'max_days_with_pay']);
        $this->assertEquals(120, $sick->annual_quota);
        $this->assertEquals(60, $sick->max_days_with_pay);
    }

    public function test_holidays_fiscal_years_and_notification_catalog(): void
    {
        $this->assertTrue(DB::table('holidays')->whereYear('date', 2026)->exists(), 'วันหยุดราชการ 2569');
        $this->assertTrue(DB::table('holidays')->whereYear('date', 2027)->exists(), 'วันหยุดราชการ 2570');
        $this->assertTrue(DB::table('fiscal_years')->exists());
        $this->assertSame(15, DB::table('notification_types')->count());
        $this->assertSame(60, DB::table('notification_templates')->count());
        $this->assertTrue(DB::table('work_schedules')->where('is_default', true)->exists());
    }

    public function test_baseline_seeds_no_accounts_roles_permissions_or_menus(): void
    {
        $this->assertSame(0, DB::table('users')->count(), 'ไม่มีบัญชีหรือรหัสผ่านตั้งต้นใน baseline');
        $this->assertSame(0, DB::table('permissions')->count(), 'permission มาจาก manifest (R-04) เท่านั้น');
        $this->assertSame(0, DB::table('permission_templates')->count());
        $this->assertSame(0, DB::table('personnel')->count());
    }

    public function test_seeders_are_idempotent(): void
    {
        $tables = ['org_unit_types', 'org_units', 'personnel_types', 'leave_types', 'leave_policies', 'holidays',
            'fiscal_years', 'notification_types', 'notification_templates', 'work_schedules', 'work_schedule_assignments'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

        $this->seed(DatabaseSeeder::class);

        $after = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $this->assertSame($before, $after);
    }
}
