<?php

namespace Tests\Fixtures;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Personnel;
use App\Models\Position;
use App\Services\Org\OrgTree;
use App\Services\Personnel\AssignmentService;
use Database\Seeders\FieldClassificationSeeder;
use Database\Seeders\OrganizationBaselineSeeder;
use Database\Seeders\OrgUnitTypeSeeder;
use Database\Seeders\PersonnelTypeSeeder;

/**
 * โครงสร้างวิทยาลัยจำลองสำหรับเทสต์ CORE-01/02 (และใช้ต่อใน R-04 persona tests)
 *
 * วิทยาลัย (L1)
 * ├─ ฝ่ายบริหารทรัพยากร (L2)
 * │   └─ งานบุคลากร (L3 section)
 * ├─ ฝ่ายวิชาการ (L2)
 * │   ├─ แผนกวิชาช่างยนต์ (L3 department)
 * │   │   └─ สาขางานยานยนต์ (L4 program)
 * │   └─ สาขางานทดลอง (L4 program ใต้ฝ่ายโดยตรง — ไม่มีชั้น L3)
 * ├─ ฝ่ายยุทธศาสตร์และแผนงาน (L2)
 * └─ ฝ่ายกิจการนักเรียน นักศึกษา (L2)
 *
 * บุคลากรหลายตำแหน่ง "สมชาย" (MULTI):
 *   primary    ครู @ แผนกวิชาช่างยนต์                ตั้งแต่ 2026-05-16
 *   management หัวหน้าแผนกวิชา @ แผนกวิชาช่างยนต์      ตั้งแต่ 2026-05-16
 *   management รองผู้อำนวยการ @ ฝ่ายบริหารทรัพยากร    ตั้งแต่ 2026-06-01
 *   acting     ผู้อำนวยการ @ วิทยาลัย (แทน ผอ. "สมศักดิ์") 2026-11-01 ถึง 2026-11-15
 */
class CollegeFixture
{
    public const START = '2026-05-16';

    public const ACTING_FROM = '2026-11-01';

    public const ACTING_TO = '2026-11-15';

    /** @var array<string, OrgUnit> */
    public array $units = [];

    /** @var array<string, Position> */
    public array $positions = [];

    /** @var array<string, Personnel> */
    public array $people = [];

    /** @var array<string, Assignment> */
    public array $assignments = [];

    public static function build(bool $withPeople = true): self
    {
        $fixture = new self;
        (new OrgUnitTypeSeeder)->run();
        (new OrganizationBaselineSeeder)->run();
        (new PersonnelTypeSeeder)->run();
        (new FieldClassificationSeeder)->run();

        $fixture->buildUnits();
        $fixture->buildPositions();

        if ($withPeople) {
            $fixture->buildPeople();
        }

        return $fixture;
    }

    private function buildUnits(): void
    {
        $tree = app(OrgTree::class);
        $type = fn (string $code) => OrgUnitType::where('code', $code)->value('id');

        $this->units['root'] = OrgUnit::where('code', 'ORG-ROOT')->firstOrFail();
        $this->units['resources'] = OrgUnit::where('code', 'DIV-RESOURCES')->firstOrFail();
        $this->units['academic'] = OrgUnit::where('code', 'DIV-ACADEMIC')->firstOrFail();
        $this->units['strategy'] = OrgUnit::where('code', 'DIV-STRATEGY')->firstOrFail();

        $this->units['hr'] = $tree->create(['code' => 'SEC-HR', 'name' => 'งานบุคลากร', 'org_unit_type_id' => $type('section'), 'effective_from' => '2026-03-24'], $this->units['resources']);
        $this->units['auto'] = $tree->create(['code' => 'DEP-AUTO', 'name' => 'แผนกวิชาช่างยนต์', 'org_unit_type_id' => $type('department'), 'effective_from' => '2026-03-24'], $this->units['academic']);
        $this->units['auto_program'] = $tree->create(['code' => 'PRG-AUTO', 'name' => 'สาขางานยานยนต์', 'org_unit_type_id' => $type('program'), 'effective_from' => '2026-03-24'], $this->units['auto']);
        $this->units['pilot_program'] = $tree->create(['code' => 'PRG-PILOT', 'name' => 'สาขางานทดลอง', 'org_unit_type_id' => $type('program'), 'effective_from' => '2026-03-24'], $this->units['academic']);
    }

    private function buildPositions(): void
    {
        $type = fn (string $code) => OrgUnitType::where('code', $code)->value('id');

        $this->positions['teacher'] = Position::create(['code' => 'TEACHER', 'name' => 'ครู', 'kind' => Position::KIND_LINE]);
        $this->positions['staff'] = Position::create(['code' => 'STAFF', 'name' => 'เจ้าหน้าที่', 'kind' => Position::KIND_LINE]);
        $this->positions['director'] = Position::create(['code' => 'DIRECTOR', 'name' => 'ผู้อำนวยการ', 'kind' => Position::KIND_MANAGEMENT, 'org_unit_type_id' => $type('institution')]);
        $this->positions['deputy'] = Position::create(['code' => 'DEPUTY', 'name' => 'รองผู้อำนวยการ', 'kind' => Position::KIND_MANAGEMENT, 'org_unit_type_id' => $type('division')]);
        $this->positions['section_head'] = Position::create(['code' => 'SECTION-HEAD', 'name' => 'หัวหน้างาน', 'kind' => Position::KIND_MANAGEMENT, 'org_unit_type_id' => $type('section')]);
        $this->positions['department_head'] = Position::create(['code' => 'DEPT-HEAD', 'name' => 'หัวหน้าแผนกวิชา', 'kind' => Position::KIND_MANAGEMENT, 'org_unit_type_id' => $type('department')]);
        $this->positions['program_head'] = Position::create(['code' => 'PROGRAM-HEAD', 'name' => 'หัวหน้าสาขางาน', 'kind' => Position::KIND_MANAGEMENT, 'org_unit_type_id' => $type('program')]);
    }

    private function buildPeople(): void
    {
        $service = app(AssignmentService::class);
        $order = fn (string $n) => ['order_number' => "คำสั่ง {$n}/2569", 'order_date' => '2026-05-01'];

        $this->people['director'] = Personnel::factory()->create(['personnel_code' => 'D001', 'first_name' => 'สมศักดิ์', 'last_name' => 'ผู้อำนวยการ']);
        $this->people['multi'] = Personnel::factory()->create(['personnel_code' => 'T001', 'first_name' => 'สมชาย', 'last_name' => 'หลายตำแหน่ง']);
        $this->people['teacher'] = Personnel::factory()->create(['personnel_code' => 'T002', 'first_name' => 'สมหญิง', 'last_name' => 'ครูช่างยนต์']);
        $this->people['hr_staff'] = Personnel::factory()->create(['personnel_code' => 'S001', 'first_name' => 'สมปอง', 'last_name' => 'งานบุคลากร']);

        $this->assignments['director'] = $service->add($this->people['director'], ['kind' => 'management', 'org_unit_id' => $this->units['root']->id, 'position_id' => $this->positions['director']->id, 'effective_from' => self::START] + $order('1'));

        $this->assignments['multi_primary'] = $service->add($this->people['multi'], ['kind' => 'primary', 'org_unit_id' => $this->units['auto']->id, 'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START] + $order('2'));
        $this->assignments['multi_dept_head'] = $service->add($this->people['multi'], ['kind' => 'management', 'org_unit_id' => $this->units['auto']->id, 'position_id' => $this->positions['department_head']->id, 'effective_from' => self::START] + $order('3'));
        $this->assignments['multi_deputy'] = $service->add($this->people['multi'], ['kind' => 'management', 'org_unit_id' => $this->units['resources']->id, 'position_id' => $this->positions['deputy']->id, 'effective_from' => '2026-06-01'] + $order('4'));
        $this->assignments['multi_acting_director'] = $service->add($this->people['multi'], ['kind' => 'acting', 'acting_for_assignment_id' => $this->assignments['director']->id, 'effective_from' => self::ACTING_FROM, 'effective_to' => self::ACTING_TO] + $order('5'));

        $this->assignments['teacher_primary'] = $service->add($this->people['teacher'], ['kind' => 'primary', 'org_unit_id' => $this->units['auto_program']->id, 'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START] + $order('6'));
        $this->assignments['hr_primary'] = $service->add($this->people['hr_staff'], ['kind' => 'primary', 'org_unit_id' => $this->units['hr']->id, 'position_id' => $this->positions['staff']->id, 'effective_from' => self::START] + $order('7'));
    }
}
