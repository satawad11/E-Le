<?php

namespace Tests\Feature\Core;

use App\Models\Assignment;
use App\Services\Org\OrgTree;
use App\Services\Personnel\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * คนเดียวหลายตำแหน่ง (R-03 prompt): ครู แผนกช่างยนต์ + หัวหน้าแผนกช่างยนต์ + รองผอ. ฝ่ายบริหารทรัพยากร
 * + รักษาการ ผอ. ช่วงสั้น — แต่ละ assignment แยกกันตามหน่วยและวันที่มีผล ไม่รวมเป็น "ระดับสูงสุด"
 */
class MultiPositionPersonnelTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    private OrgTree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build();
        $this->tree = app(OrgTree::class);
    }

    /** @return array<int, string> */
    private function effective(string $date): array
    {
        return Assignment::where('personnel_id', $this->f->people['multi']->id)
            ->effectiveOn($date)
            ->with(['orgUnit', 'position'])
            ->get()
            ->map(fn (Assignment $a) => "{$a->kind}:{$a->position->code}@{$a->orgUnit->code}")
            ->sort()->values()->all();
    }

    public function test_effective_assignments_change_with_the_date(): void
    {
        $this->assertSame([
            'management:DEPT-HEAD@DEP-AUTO',
            'primary:TEACHER@DEP-AUTO',
        ], $this->effective('2026-05-20'));

        $this->assertSame([
            'management:DEPT-HEAD@DEP-AUTO',
            'management:DEPUTY@DIV-RESOURCES',
            'primary:TEACHER@DEP-AUTO',
        ], $this->effective('2026-10-01'));

        $this->assertSame([
            'acting:DIRECTOR@ORG-ROOT',
            'management:DEPT-HEAD@DEP-AUTO',
            'management:DEPUTY@DIV-RESOURCES',
            'primary:TEACHER@DEP-AUTO',
        ], $this->effective('2026-11-05'));

        $this->assertSame($this->effective('2026-10-01'), $this->effective('2026-11-16'), 'รักษาการหมดผลหลังวันสิ้นสุดคำสั่ง');
    }

    public function test_he_heads_each_unit_only_in_its_own_capacity(): void
    {
        $multi = $this->f->people['multi']->id;

        $this->assertSame($multi, $this->tree->headOf($this->f->units['auto'], '2026-10-01')->personnel_id);
        $this->assertSame($multi, $this->tree->headOf($this->f->units['resources'], '2026-10-01')->personnel_id);
        $this->assertNull($this->tree->headOf($this->f->units['academic'], '2026-10-01'), 'รองผอ. ฝ่ายบริหารทรัพยากรไม่ใช่หัวหน้าฝ่ายวิชาการ');
        $this->assertSame($this->f->people['director']->id, $this->tree->headOf($this->f->units['root'], '2026-10-01')->personnel_id);
        $this->assertSame($multi, $this->tree->headOf($this->f->units['root'], '2026-11-05')->personnel_id);
        $this->assertSame(Assignment::KIND_ACTING, $this->tree->headOf($this->f->units['root'], '2026-11-05')->kind);
    }

    public function test_the_level_two_above_his_home_unit_is_academic_not_resources(): void
    {
        // หน่วยต้นสังกัด (primary) คือแผนกช่างยนต์ — ระดับ 2 ที่อยู่เหนือคือฝ่ายวิชาการ
        // ตำแหน่งรองผอ. ฝ่ายบริหารทรัพยากรของเขาเองไม่ได้ทำให้ฝ่ายบริหารทรัพยากรเป็นสายบังคับบัญชาของงานในแผนก
        $home = $this->f->assignments['multi_primary']->orgUnit;

        $this->assertSame('DIV-ACADEMIC', $this->tree->nearestOfLevel($home, 2)->code);
        $this->assertSame('DEP-AUTO', $this->tree->nearestOfLevel($home, 3)->code);
        $this->assertNotContains($home->id, $this->tree->subtreeIds($this->f->units['resources']));
    }

    public function test_a_second_primary_is_rejected_even_with_many_other_positions(): void
    {
        $this->expectException(ValidationException::class);

        app(AssignmentService::class)->add($this->f->people['multi'], [
            'kind' => 'primary', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id,
            'effective_from' => '2026-11-05', 'order_number' => 'คำสั่ง 10/2569', 'order_date' => '2026-11-01',
        ]);
    }
}
