<?php

namespace Tests\Fixtures;

use App\Authz\Manifest\ModuleSynchronizer;
use App\Models\Assignment;
use App\Models\Authz\PermissionTemplate;
use App\Models\Authz\RoleGrant;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Personnel;
use App\Models\Position;
use App\Models\User;
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

    /** @var array<string, User> บัญชีของ persona ตาม PLAN §8.3 (สร้างโดย withAccessPersonas) */
    public array $users = [];

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

    /**
     * persona ตาม PLAN §8.3 สำหรับ R-04: sync manifest แล้วสร้างบัญชี + ตำแหน่ง + role grant
     *
     * L5 teacher · L4 program_head · L3 section_head · L2 deputy_academic · L1 director
     * · hr (งานบุคคล = hr_staff + personnel.hr_officer) · admin (system.admin) · auditor (audit.auditor) · multi (หลายตำแหน่ง)
     */
    public function withAccessPersonas(): self
    {
        app(ModuleSynchronizer::class)->sync();

        $service = app(AssignmentService::class);
        $order = fn (string $n) => ['order_number' => "คำสั่ง {$n}/2569", 'order_date' => '2026-05-01'];

        $this->people['program_head'] = Personnel::factory()->create(['personnel_code' => 'T003', 'first_name' => 'สมศรี', 'last_name' => 'หัวหน้าสาขา']);
        $this->people['section_head'] = Personnel::factory()->create(['personnel_code' => 'S002', 'first_name' => 'สมพร', 'last_name' => 'หัวหน้างานบุคลากร']);
        $this->people['deputy_academic'] = Personnel::factory()->create(['personnel_code' => 'D002', 'first_name' => 'สมบัติ', 'last_name' => 'รองวิชาการ']);
        $this->people['admin'] = Personnel::factory()->create(['personnel_code' => 'S003', 'first_name' => 'สมคิด', 'last_name' => 'ผู้ดูแลระบบ']);
        $this->people['auditor'] = Personnel::factory()->create(['personnel_code' => 'S004', 'first_name' => 'สมใจ', 'last_name' => 'ผู้ตรวจสอบ']);

        $service->add($this->people['program_head'], ['kind' => 'primary', 'org_unit_id' => $this->units['auto_program']->id, 'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START] + $order('11'));
        $this->assignments['program_head'] = $service->add($this->people['program_head'], ['kind' => 'management', 'org_unit_id' => $this->units['auto_program']->id, 'position_id' => $this->positions['program_head']->id, 'effective_from' => self::START] + $order('12'));
        $service->add($this->people['section_head'], ['kind' => 'primary', 'org_unit_id' => $this->units['hr']->id, 'position_id' => $this->positions['staff']->id, 'effective_from' => self::START] + $order('13'));
        $this->assignments['section_head'] = $service->add($this->people['section_head'], ['kind' => 'management', 'org_unit_id' => $this->units['hr']->id, 'position_id' => $this->positions['section_head']->id, 'effective_from' => self::START] + $order('14'));
        $service->add($this->people['deputy_academic'], ['kind' => 'primary', 'org_unit_id' => $this->units['academic']->id, 'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START] + $order('15'));
        $this->assignments['deputy_academic'] = $service->add($this->people['deputy_academic'], ['kind' => 'management', 'org_unit_id' => $this->units['academic']->id, 'position_id' => $this->positions['deputy']->id, 'effective_from' => self::START] + $order('16'));
        $service->add($this->people['admin'], ['kind' => 'primary', 'org_unit_id' => $this->units['hr']->id, 'position_id' => $this->positions['staff']->id, 'effective_from' => self::START] + $order('17'));
        $service->add($this->people['auditor'], ['kind' => 'primary', 'org_unit_id' => $this->units['resources']->id, 'position_id' => $this->positions['staff']->id, 'effective_from' => self::START] + $order('18'));

        foreach (['teacher', 'program_head', 'section_head', 'deputy_academic', 'director', 'hr_staff', 'admin', 'auditor', 'multi'] as $key) {
            $this->users[$key] = User::factory()->create(['personnel_id' => $this->people[$key]->id, 'username' => $key]);
        }
        $this->users['hr'] = $this->users['hr_staff'];

        $this->grant('hr', 'personnel.hr_officer', 'คำสั่ง 20/2569');
        $this->grant('admin', 'system.admin', 'คำสั่ง 21/2569');
        $this->grant('auditor', 'audit.auditor', 'คำสั่ง 22/2569');

        return $this;
    }

    /**
     * persona ต้นแบบหน้า 12 (R-06): ครูแผนกวิชาช่างยนต์ที่ได้ชุดสิทธิ์ "HR พัฒนาบุคลากร" เฉพาะแผนกตน (`trainer`)
     * ต้องเรียกหลัง withAccessPersonas()
     */
    public function withLimitedHrPersona(): self
    {
        $this->people['trainer'] = Personnel::factory()->create(['personnel_code' => 'T004', 'title' => 'นาย', 'first_name' => 'สมเกียรติ', 'last_name' => 'ครูพัฒนา']);
        app(AssignmentService::class)->add($this->people['trainer'], ['kind' => 'primary', 'org_unit_id' => $this->units['auto']->id,
            'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START, 'order_number' => 'คำสั่ง 19/2569', 'order_date' => '2026-05-01']);
        $this->users['trainer'] = User::factory()->create(['personnel_id' => $this->people['trainer']->id, 'username' => 'trainer']);
        $grant = $this->grant('trainer', 'personnel.hr_development', 'คำสั่ง 503/2569', ['org_unit_ids' => [$this->units['auto']->id]], '2027-03-31');
        $grant->forceFill(['approved_by' => $this->users['section_head']->id])->save();

        return $this;
    }

    /** @param  array<string, mixed>  $attributes */
    public function grant(string $userKey, string $templateCode, string $orderNumber, array $attributes = [], string $expires = '2027-05-15'): RoleGrant
    {
        return RoleGrant::create([
            'user_id' => $this->users[$userKey]->id,
            'template_id' => PermissionTemplate::where('code', $templateCode)->value('id'),
            'attributes' => $attributes ?: null,
            'order_number' => $orderNumber,
            'granted_by' => $this->users['director']->id,
            'approved_by' => $this->users['director']->id,
            'effective_from' => self::START,
            'expires_at' => $expires,
        ]);
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
        $this->positions['executive'] = Position::create(['code' => 'EXECUTIVE', 'name' => 'ผู้บริหารสถานศึกษา', 'kind' => Position::KIND_LINE]);
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

        $this->assignments['director_primary'] = $service->add($this->people['director'], ['kind' => 'primary', 'org_unit_id' => $this->units['root']->id, 'position_id' => $this->positions['executive']->id, 'effective_from' => self::START] + $order('0'));
        $this->assignments['director'] = $service->add($this->people['director'], ['kind' => 'management', 'org_unit_id' => $this->units['root']->id, 'position_id' => $this->positions['director']->id, 'effective_from' => self::START] + $order('1'));

        $this->assignments['multi_primary'] = $service->add($this->people['multi'], ['kind' => 'primary', 'org_unit_id' => $this->units['auto']->id, 'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START] + $order('2'));
        $this->assignments['multi_dept_head'] = $service->add($this->people['multi'], ['kind' => 'management', 'org_unit_id' => $this->units['auto']->id, 'position_id' => $this->positions['department_head']->id, 'effective_from' => self::START] + $order('3'));
        $this->assignments['multi_deputy'] = $service->add($this->people['multi'], ['kind' => 'management', 'org_unit_id' => $this->units['resources']->id, 'position_id' => $this->positions['deputy']->id, 'effective_from' => '2026-06-01'] + $order('4'));
        $this->assignments['multi_acting_director'] = $service->add($this->people['multi'], ['kind' => 'acting', 'acting_for_assignment_id' => $this->assignments['director']->id, 'effective_from' => self::ACTING_FROM, 'effective_to' => self::ACTING_TO] + $order('5'));

        $this->assignments['teacher_primary'] = $service->add($this->people['teacher'], ['kind' => 'primary', 'org_unit_id' => $this->units['auto_program']->id, 'position_id' => $this->positions['teacher']->id, 'effective_from' => self::START] + $order('6'));
        $this->assignments['hr_primary'] = $service->add($this->people['hr_staff'], ['kind' => 'primary', 'org_unit_id' => $this->units['hr']->id, 'position_id' => $this->positions['staff']->id, 'effective_from' => self::START] + $order('7'));
    }
}
