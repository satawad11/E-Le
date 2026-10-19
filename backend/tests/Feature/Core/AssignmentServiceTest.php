<?php

namespace Tests\Feature\Core;

use App\Events\AssignmentChanged;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Services\Personnel\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * CORE-02 กฎ assignment: primary ซ้อนไม่ได้ · management ของหน่วยซ้อนไม่ได้ · ห้ามเขียนทับ · event ทุกครั้ง
 */
class AssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssignmentService $service;

    private CollegeFixture $f;

    private const ORDER = ['order_number' => 'คำสั่ง 99/2569', 'order_date' => '2026-05-01'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build();
        $this->service = app(AssignmentService::class);
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("ต้องถูกปฏิเสธที่ {$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_primary_assignments_cannot_overlap(): void
    {
        $this->assertRejected(fn () => $this->service->add($this->f->people['multi'], [
            'kind' => 'primary', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id,
            'effective_from' => '2026-09-01',
        ] + self::ORDER), 'effective_from');

        $this->assertSame(1, Assignment::where('personnel_id', $this->f->people['multi']->id)->where('kind', 'primary')->count());
    }

    public function test_moving_primary_means_ending_the_old_row_and_adding_a_new_one(): void
    {
        $person = $this->f->people['teacher'];
        $old = $this->f->assignments['teacher_primary'];

        $this->service->end($old, '2026-08-31', 'ย้ายไปงานบุคลากร');
        $new = $this->service->add($person, [
            'kind' => 'primary', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id,
            'effective_from' => '2026-09-01',
        ] + self::ORDER);

        $old->refresh();
        $this->assertSame('2026-08-31', $old->effective_to->toDateString());
        $this->assertSame('ย้ายไปงานบุคลากร', $old->end_reason);
        $this->assertSame([$old->id], Assignment::where('personnel_id', $person->id)->where('kind', 'primary')->effectiveOn('2026-08-31')->pluck('id')->all());
        $this->assertSame([$new->id], Assignment::where('personnel_id', $person->id)->where('kind', 'primary')->effectiveOn('2026-09-01')->pluck('id')->all());
    }

    public function test_a_unit_has_one_management_holder_at_a_time_excluding_acting(): void
    {
        $this->assertRejected(fn () => $this->service->add($this->f->people['teacher'], [
            'kind' => 'management', 'org_unit_id' => $this->f->units['auto']->id, 'position_id' => $this->f->positions['department_head']->id,
            'effective_from' => '2026-07-01',
        ] + self::ORDER), 'effective_from');
    }

    public function test_management_position_must_match_the_unit_type(): void
    {
        $this->assertRejected(fn () => $this->service->add($this->f->people['teacher'], [
            'kind' => 'management', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['department_head']->id,
            'effective_from' => '2026-07-01',
        ] + self::ORDER), 'position_id');

        $this->assertRejected(fn () => $this->service->add($this->f->people['teacher'], [
            'kind' => 'management', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['teacher']->id,
            'effective_from' => '2026-07-01',
        ] + self::ORDER), 'position_id');
    }

    public function test_primary_must_be_a_line_position(): void
    {
        $person = Personnel::factory()->create();

        $this->assertRejected(fn () => $this->service->add($person, [
            'kind' => 'primary', 'org_unit_id' => $this->f->units['strategy']->id, 'position_id' => $this->f->positions['deputy']->id,
            'effective_from' => '2026-07-01',
        ] + self::ORDER), 'position_id');
    }

    public function test_order_number_is_required_except_for_initially_imported_primary(): void
    {
        $person = Personnel::factory()->create();
        $data = ['kind' => 'primary', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id, 'effective_from' => '2026-07-01'];

        $this->assertRejected(fn () => $this->service->add($person, $data), 'order_number');

        $this->assertNull($this->service->add($person, $data, null, initialImport: true)->order_number);

        $this->assertRejected(fn () => $this->service->add($person, [
            'kind' => 'duty', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id, 'effective_from' => '2026-07-01',
        ], null, initialImport: true), 'order_number');
    }

    public function test_acting_needs_a_management_target_an_end_date_and_another_person(): void
    {
        $person = $this->f->people['teacher'];
        $base = ['kind' => 'acting', 'effective_from' => '2026-12-01'] + self::ORDER;

        $this->assertRejected(fn () => $this->service->add($person, $base + ['acting_for_assignment_id' => $this->f->assignments['director']->id]), 'effective_to');
        $this->assertRejected(fn () => $this->service->add($person, $base + ['effective_to' => '2026-12-05']), 'acting_for_assignment_id');
        $this->assertRejected(fn () => $this->service->add($person, $base + ['effective_to' => '2026-12-05', 'acting_for_assignment_id' => $this->f->assignments['multi_primary']->id]), 'acting_for_assignment_id');
        $this->assertRejected(fn () => $this->service->add($this->f->people['director'], $base + ['effective_to' => '2026-12-05', 'acting_for_assignment_id' => $this->f->assignments['director']->id]), 'acting_for_assignment_id');

        // รักษาการซ้อนกับคำสั่งรักษาการเดิมไม่ได้
        $this->assertRejected(fn () => $this->service->add($person, ['kind' => 'acting', 'effective_from' => '2026-11-10', 'effective_to' => '2026-11-20', 'acting_for_assignment_id' => $this->f->assignments['director']->id] + self::ORDER), 'effective_from');

        $acting = $this->service->add($person, $base + ['effective_to' => '2026-12-05', 'acting_for_assignment_id' => $this->f->assignments['director']->id]);
        $this->assertSame($this->f->units['root']->id, $acting->org_unit_id);
        $this->assertSame($this->f->positions['director']->id, $acting->position_id);
    }

    public function test_end_cannot_extend_or_end_twice_and_needs_a_reason(): void
    {
        $acting = $this->f->assignments['multi_acting_director'];

        $this->assertRejected(fn () => $this->service->end($acting, '2026-11-30', 'ขยาย'), 'effective_to');
        $this->assertRejected(fn () => $this->service->end($acting, '2026-11-10', '   '), 'end_reason');
        $this->assertRejected(fn () => $this->service->end($acting, '2026-10-01', 'ก่อนเริ่ม'), 'effective_to');

        $this->service->end($acting, '2026-11-10', 'ผอ. กลับมาปฏิบัติราชการก่อนกำหนด');
        $this->assertRejected(fn () => $this->service->end($acting->fresh(), '2026-11-09', 'ซ้ำ'), 'assignment');
    }

    public function test_every_change_dispatches_assignment_changed_and_is_audited(): void
    {
        Event::fake([AssignmentChanged::class]);
        $person = Personnel::factory()->create();

        $assignment = $this->service->add($person, [
            'kind' => 'duty', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id, 'effective_from' => '2026-07-01',
        ] + self::ORDER);
        $this->service->end($assignment, '2026-07-31', 'ครบวาระ');

        Event::assertDispatched(AssignmentChanged::class, fn ($e) => $e->assignment->is($assignment) && $e->action === AssignmentChanged::CREATED);
        Event::assertDispatched(AssignmentChanged::class, fn ($e) => $e->assignment->is($assignment) && $e->action === AssignmentChanged::ENDED);
        $this->assertTrue(AuditLog::where('action', 'assignment.created')->where('resource_id', (string) $assignment->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'assignment.ended')->where('resource_id', (string) $assignment->id)->exists());
    }

    public function test_service_offers_no_way_to_overwrite_an_assignment(): void
    {
        $this->assertFalse(method_exists(AssignmentService::class, 'update'));
        $this->assertFalse(method_exists(AssignmentService::class, 'delete'));
    }

    public function test_separated_personnel_cannot_get_new_assignments(): void
    {
        $person = Personnel::factory()->create(['status' => 'separated']);

        $this->assertRejected(fn () => $this->service->add($person, [
            'kind' => 'duty', 'org_unit_id' => $this->f->units['hr']->id, 'position_id' => $this->f->positions['staff']->id, 'effective_from' => '2026-07-01',
        ] + self::ORDER), 'personnel');
    }
}
