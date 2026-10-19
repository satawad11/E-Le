<?php

namespace Tests\Feature\Core;

use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Services\Org\OrgTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * CORE-01 Tests ขั้นต่ำ: path ถูกต้องหลังย้าย subtree · ห้ามวงวน · level ลูก > parent
 * · nearestOfLevel ข้ามชั้นที่ไม่มีได้ · headOf คืน acting ในช่วงคำสั่ง
 */
class OrgTreeTest extends TestCase
{
    use RefreshDatabase;

    private OrgTree $tree;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build();
        $this->tree = app(OrgTree::class);
    }

    private function typeId(string $code): int
    {
        return OrgUnitType::where('code', $code)->value('id');
    }

    public function test_create_sets_materialized_path_and_depth(): void
    {
        $root = $this->f->units['root'];
        $academic = $this->f->units['academic'];
        $auto = $this->f->units['auto'];
        $program = $this->f->units['auto_program'];

        $this->assertSame("/{$root->id}/{$academic->id}/{$auto->id}/", $auto->path);
        $this->assertSame(2, $auto->depth);
        $this->assertSame("{$auto->path}{$program->id}/", $program->path);
        $this->assertSame(3, $program->depth);
    }

    public function test_moving_a_unit_rewrites_the_path_of_its_whole_subtree(): void
    {
        $auto = $this->f->units['auto'];
        $program = $this->f->units['auto_program'];
        $resources = $this->f->units['resources'];

        $this->tree->move($auto, $resources);

        $auto->refresh();
        $program->refresh();
        $this->assertSame($resources->id, $auto->parent_id);
        $this->assertSame($resources->path.$auto->id.'/', $auto->path);
        $this->assertSame($auto->path.$program->id.'/', $program->path);
        $this->assertSame(2, $auto->depth);
        $this->assertSame(3, $program->depth);
        $this->assertContains($program->id, $this->tree->subtreeIds($resources));
        $this->assertNotContains($program->id, $this->tree->subtreeIds($this->f->units['academic']));
    }

    public function test_cannot_move_a_unit_under_itself_or_its_descendant(): void
    {
        $academic = $this->f->units['academic'];

        foreach ([$academic, $this->f->units['auto']] as $target) {
            try {
                $this->tree->move($academic, $target);
                $this->fail('ต้องห้ามวงวน');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('วงวน', $e->errors()['parent_uuid'][0]);
            }
        }

        $this->assertSame($this->f->units['root']->id, $academic->fresh()->parent_id);
    }

    public function test_child_level_must_be_greater_than_parent_level(): void
    {
        $this->expectException(ValidationException::class);

        // ฝ่าย (L2) อยู่ใต้แผนกวิชา (L3) ไม่ได้
        $this->tree->create(['code' => 'BAD', 'name' => 'ฝ่ายผิดที่', 'org_unit_type_id' => $this->typeId('division'), 'effective_from' => '2026-05-16'], $this->f->units['auto']);
    }

    public function test_moving_also_checks_levels(): void
    {
        $this->expectException(ValidationException::class);

        // แผนกวิชา (L3) ย้ายไปอยู่ใต้สาขางาน (L4) ไม่ได้
        $this->tree->move($this->f->units['hr'], $this->f->units['auto_program']);
    }

    public function test_only_one_active_root(): void
    {
        $this->expectException(ValidationException::class);

        $this->tree->create(['code' => 'ROOT-2', 'name' => 'วิทยาลัยที่สอง', 'org_unit_type_id' => $this->typeId('institution'), 'effective_from' => '2026-05-16'], null);
    }

    public function test_ancestors_go_from_nearest_to_root(): void
    {
        $codes = $this->tree->ancestors($this->f->units['auto_program'])->pluck('code')->all();

        $this->assertSame(['DEP-AUTO', 'DIV-ACADEMIC', 'ORG-ROOT'], $codes);
        $this->assertCount(0, $this->tree->ancestors($this->f->units['root']));
    }

    public function test_nearest_of_level_includes_self_and_skips_missing_levels(): void
    {
        $program = $this->f->units['auto_program'];
        $pilot = $this->f->units['pilot_program'];

        $this->assertSame('PRG-AUTO', $this->tree->nearestOfLevel($program, 4)->code);
        $this->assertSame('DEP-AUTO', $this->tree->nearestOfLevel($program, 3)->code);
        $this->assertSame('DIV-ACADEMIC', $this->tree->nearestOfLevel($program, 2)->code);
        $this->assertSame('ORG-ROOT', $this->tree->nearestOfLevel($program, 1)->code);

        // สาขางานทดลองอยู่ใต้ฝ่ายโดยตรง ไม่มีชั้น L3 — ข้ามไปได้และคืน null สำหรับชั้นที่ไม่มี
        $this->assertNull($this->tree->nearestOfLevel($pilot, 3));
        $this->assertSame('DIV-ACADEMIC', $this->tree->nearestOfLevel($pilot, 2)->code);
    }

    public function test_head_of_returns_acting_only_during_the_order(): void
    {
        $root = $this->f->units['root'];

        $this->assertSame($this->f->assignments['director']->id, $this->tree->headOf($root, '2026-10-31')->id);
        $this->assertSame($this->f->assignments['multi_acting_director']->id, $this->tree->headOf($root, CollegeFixture::ACTING_FROM)->id);
        $this->assertSame($this->f->assignments['multi_acting_director']->id, $this->tree->headOf($root, CollegeFixture::ACTING_TO)->id);
        $this->assertSame($this->f->assignments['director']->id, $this->tree->headOf($root, '2026-11-16')->id);
    }

    public function test_head_of_a_unit_without_a_head_is_null(): void
    {
        $this->assertNull($this->tree->headOf($this->f->units['strategy'], '2026-07-01'));
        $this->assertNull($this->tree->headOf($this->f->units['root'], '2026-01-01'), 'ก่อนคำสั่งแต่งตั้งมีผล');
    }

    public function test_closing_requires_children_and_assignments_to_be_closed_first(): void
    {
        try {
            $this->tree->close($this->f->units['academic'], '2026-12-31');
            $this->fail('ปิดหน่วยที่มีหน่วยลูกเปิดอยู่ไม่ได้');
        } catch (ValidationException) {
        }

        try {
            $this->tree->close($this->f->units['hr'], '2026-12-31');
            $this->fail('ปิดหน่วยที่ยังมีคนดำรงตำแหน่งไม่ได้');
        } catch (ValidationException) {
        }

        $closed = $this->tree->close($this->f->units['pilot_program'], '2026-12-31');
        $this->assertSame('2026-12-31', $closed->effective_to->toDateString());
        $this->assertNotNull(OrgUnit::find($closed->id), 'ปิดแทนลบ');
    }
}
