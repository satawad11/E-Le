<?php

namespace Tests\Feature\Authz;

use App\Authz\Workflow\ApproverChainResolver;
use App\Models\Personnel;
use App\Services\Personnel\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * CORE-05 (REBUILD) — resolver ตามระดับหน่วย · ข้ามผู้ยื่น/ชั้นว่าง · รวมชั้นที่เป็นคนเดียวกัน · ไม่มี fallback ตามชื่อ role
 */
class ApproverChainResolverTest extends TestCase
{
    use RefreshDatabase;

    private const CHAIN = [
        ['resolver' => 'head_of_level', 'level' => 4],
        ['resolver' => 'head_of_level', 'level' => 3],
        ['resolver' => 'head_of_level', 'level' => 2],
        ['resolver' => 'head_of_level', 'level' => 1],
    ];

    private CollegeFixture $f;

    private ApproverChainResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build()->withAccessPersonas();
        $this->resolver = app(ApproverChainResolver::class);
    }

    /** @return array<int, int> personnel id ต่อขั้น */
    private function approvers(array $result): array
    {
        return array_map(fn ($step) => $step['approvers'][0]['personnel_id'], $result['steps']);
    }

    public function test_teacher_in_a_program_climbs_every_level_of_their_own_branch(): void
    {
        $result = $this->resolver->resolve($this->f->people['teacher'], self::CHAIN, '2026-10-19');

        $this->assertSame([
            $this->f->people['program_head']->id,   // L4 สาขางานยานยนต์
            $this->f->people['multi']->id,          // L3 แผนกวิชาช่างยนต์
            $this->f->people['deputy_academic']->id, // L2 ฝ่ายวิชาการ (ไม่ใช่ฝ่ายบริหารทรัพยากรของ multi)
            $this->f->people['director']->id,       // L1
        ], $this->approvers($result));
        $this->assertFalse($result['unresolved']);
        $this->assertSame('หัวหน้าแผนกวิชา แผนกวิชาช่างยนต์', $result['steps'][1]['positions'][0]);
    }

    public function test_missing_levels_are_skipped_without_role_fallback(): void
    {
        // งานบุคลากรไม่มีชั้น L4 — ข้ามไปหัวหน้างาน (L3) แล้วขึ้นตามสาย
        $result = $this->resolver->resolve($this->f->people['hr_staff'], self::CHAIN, '2026-10-19');

        $this->assertSame([$this->f->people['section_head']->id, $this->f->people['multi']->id, $this->f->people['director']->id], $this->approvers($result));
        $this->assertSame([0], array_column($result['skipped'], 'index'));
        $this->assertSame('ไม่พบผู้ดำรงตำแหน่ง', $result['skipped'][0]['reason']);
    }

    public function test_requester_is_never_their_own_approver(): void
    {
        // multi ยื่นเอง: L3 คือตัวเขา → ข้าม · L2 ฝ่ายวิชาการ = deputy_academic · L1 = ผอ.
        $result = $this->resolver->resolve($this->f->people['multi'], self::CHAIN, '2026-10-19');

        $this->assertSame([$this->f->people['deputy_academic']->id, $this->f->people['director']->id], $this->approvers($result));
        $this->assertContains('ผู้อนุมัติคือผู้ยื่นเอง', array_column($result['skipped'], 'reason'));
    }

    public function test_consecutive_steps_by_the_same_person_merge_and_record_every_position(): void
    {
        // ช่วงที่ multi รักษาการ ผอ.: หัวหน้างานบุคลากรยื่น → L2 (multi รองผอ.) และ L1 (multi รักษาการ) เป็นคนเดียวกัน
        $result = $this->resolver->resolve($this->f->people['section_head'], self::CHAIN, CollegeFixture::ACTING_FROM);

        $this->assertSame([$this->f->people['multi']->id], $this->approvers($result));
        $this->assertSame([2, 3], $result['steps'][0]['merged_steps']);
        $this->assertSame(['รองผู้อำนวยการ ฝ่ายบริหารทรัพยากร', 'รักษาการในตำแหน่งผู้อำนวยการ วิทยาลัยเทคนิคดอนเมือง'], $result['steps'][0]['positions']);
        $this->assertTrue(collect($result['steps'][0]['approvers'])->contains('acting', true), 'บันทึก assignment รักษาการที่ใช้ลงนาม');
    }

    public function test_no_approver_at_all_goes_to_the_hr_queue(): void
    {
        $orphan = Personnel::factory()->create();
        app(AssignmentService::class)->add($orphan, ['kind' => 'primary', 'org_unit_id' => $this->f->units['strategy']->id,
            'position_id' => $this->f->positions['staff']->id, 'effective_from' => '2026-05-16'], null, initialImport: true);

        $result = $this->resolver->resolve($orphan, [['resolver' => 'head_of_level', 'level' => 2]], '2026-10-19');

        $this->assertTrue($result['unresolved']);
        $this->assertSame([], $result['steps']);
    }

    public function test_skip_policy_queue_stops_at_the_first_missing_approver(): void
    {
        $result = $this->resolver->resolve($this->f->people['hr_staff'], [
            ['resolver' => 'head_of_level', 'level' => 4, 'skip_policy' => 'queue'],
            ['resolver' => 'head_of_level', 'level' => 1],
        ], '2026-10-19');

        $this->assertTrue($result['unresolved']);
    }

    public function test_functional_template_and_specific_assignment_resolvers(): void
    {
        $result = $this->resolver->resolve($this->f->people['teacher'], [
            ['resolver' => 'functional_template', 'template' => 'personnel.hr_officer'],
            ['resolver' => 'specific_assignment', 'assignment_uuid' => $this->f->assignments['director']->uuid],
        ], '2026-10-19');

        $this->assertSame([$this->f->people['hr_staff']->id, $this->f->people['director']->id], $this->approvers($result));
        $this->assertSame('งานบุคคล (ทะเบียนประวัติ)', $result['steps'][0]['positions'][0]);
    }
}
