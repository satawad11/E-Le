<?php

namespace Tests\Feature;

use App\Models\OrgUnit;
use Database\Seeders\VocationalOrgStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ผังหน่วยงานตั้งต้นของสถานศึกษาอาชีวศึกษา
 *
 * ก่อนหน้านี้การติดตั้งใหม่ได้ผังแบนราบชั้นเดียว — ยอดผังกับหน่วยงานชื่อกลาง ๆ
 * สองหน่วย · ไม่มีชั้น "ฝ่าย" ให้สายอนุมัติไต่หาหัวหน้า หรือให้รายงานกรองตามฝ่าย
 *
 * เทสต์นี้ตรึงสามอย่าง: โครงถูกสร้างครบและต่อกันถูกชั้น · รันซ้ำไม่สร้างซ้ำ ·
 * และ **ไม่ทับ** ชื่อหรือสังกัดที่ผู้ดูแลแก้ไว้เอง ซึ่งเป็นข้อห้ามของ PROJECT_RULES
 */
class VocationalOrgStructureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_the_four_division_vocational_structure_under_the_root(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $root = OrgUnit::whereNull('parent_id')->firstOrFail();

        $divisions = OrgUnit::where('type', 'division')->get();
        $this->assertCount(4, $divisions);
        $this->assertTrue($divisions->every(fn (OrgUnit $unit) => $unit->parent_id === $root->id));

        $this->assertSame(
            ['ฝ่ายบริหารทรัพยากร', 'ฝ่ายแผนงานและความร่วมมือ', 'ฝ่ายพัฒนากิจการนักเรียนนักศึกษา', 'ฝ่ายวิชาการ'],
            $divisions->sortBy('id')->pluck('name')->all(),
        );

        // งานทุกงานต้องสังกัดฝ่าย ไม่ใช่แขวนใต้ยอดผัง
        $sections = OrgUnit::where('type', 'section')->get();
        $this->assertCount(25, $sections);
        $this->assertTrue($sections->every(
            fn (OrgUnit $unit) => $divisions->contains('id', $unit->parent_id),
        ));

        // งานบุคลากรและงานทะเบียนสังกัดฝ่ายบริหารทรัพยากรตามระเบียบ สอศ.
        $resource = OrgUnit::where('code', 'ORG-DIV-RESOURCE')->firstOrFail();
        foreach (['ORG-SEC-HR', 'ORG-SEC-REGISTRAR', 'ORG-SEC-FINANCE'] as $code) {
            $this->assertSame($resource->id, OrgUnit::where('code', $code)->firstOrFail()->parent_id);
        }
    }

    public function test_running_it_twice_does_not_duplicate_units(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);
        $before = OrgUnit::count();

        $this->seed(VocationalOrgStructureSeeder::class);

        $this->assertSame($before, OrgUnit::count());
    }

    public function test_it_never_overwrites_names_or_parents_that_the_administrator_changed(): void
    {
        $this->seed(VocationalOrgStructureSeeder::class);

        $root = OrgUnit::whereNull('parent_id')->firstOrFail();
        $hr = OrgUnit::where('code', 'ORG-SEC-HR')->firstOrFail();
        $hr->update(['name' => 'งานบุคลากรและนิติการ', 'parent_id' => $root->id]);

        $this->seed(VocationalOrgStructureSeeder::class);

        $hr->refresh();
        $this->assertSame('งานบุคลากรและนิติการ', $hr->name);
        $this->assertSame($root->id, $hr->parent_id);
    }

    public function test_it_creates_the_root_itself_when_run_on_an_empty_chart(): void
    {
        OrgUnit::query()->delete();

        $this->seed(VocationalOrgStructureSeeder::class);

        $this->assertNotNull(OrgUnit::whereNull('parent_id')->first());
        $this->assertSame(4, OrgUnit::where('type', 'division')->count());
    }
}
