<?php

namespace Tests\Feature\Authz;

use App\Authz\PermissionEngine;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use App\Models\OrgUnit;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * ผลของ scopeQuery() (หน้ารายการ) ต้องตรงกับ can() (หน้ารายละเอียด) ทุกคู่ — แก้ตามผลตรวจ R-04 ข้อ 1
 *
 * ตรวจทุก persona ใน CollegeFixture × ทุกบุคลากร × personnel.profile.view และทุกหน่วย × org.unit.view
 * ทั้งก่อนและหลังตั้ง override deny แบบจำกัดหน่วย (รวมกรณี scope all และหน่วยของตนเอง)
 */
class ScopeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAS = ['teacher', 'program_head', 'section_head', 'deputy_academic', 'director', 'hr', 'admin', 'auditor', 'multi'];

    private CollegeFixture $f;

    private PermissionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build()->withAccessPersonas();
        $this->engine = app(PermissionEngine::class);
    }

    /** @return array<int, string> */
    private function mismatches(): array
    {
        $mismatches = [];
        $people = Personnel::orderBy('personnel_code')->get();
        $units = OrgUnit::orderBy('code')->get();

        foreach (self::PERSONAS as $persona) {
            $user = User::find($this->f->users[$persona]->id);

            $listed = $this->engine->scopeQuery($user, 'personnel.profile.view', Personnel::query())->pluck('id')->all();
            foreach ($people as $person) {
                $can = $this->engine->allows($user, 'personnel.profile.view', $person);
                if ($can !== in_array($person->id, $listed, true)) {
                    $mismatches[] = "personnel.profile.view {$persona} × {$person->personnel_code}: can=".var_export($can, true);
                }
            }

            $listedUnits = $this->engine->scopeQuery($user, 'org.unit.view', OrgUnit::query())->pluck('id')->all();
            foreach ($units as $unit) {
                $can = $this->engine->allows($user, 'org.unit.view', $unit);
                if ($can !== in_array($unit->id, $listedUnits, true)) {
                    $mismatches[] = "org.unit.view {$persona} × {$unit->code}: can=".var_export($can, true);
                }
            }
        }

        return $mismatches;
    }

    private function denyUnit(string $persona, string $permission, string $unit): void
    {
        PermissionOverride::create([
            'user_id' => $this->f->users[$persona]->id,
            'permission_id' => Permission::where('name', $permission)->value('id'),
            'effect' => 'deny',
            'org_unit_id' => $this->f->units[$unit]->id,
            'reason' => 'ทดสอบ deny จำกัดหน่วย',
            'granted_by' => $this->f->users['admin']->id,
        ]);
    }

    public function test_lists_match_single_checks_before_any_deny(): void
    {
        $this->assertSame([], $this->mismatches());
    }

    public function test_lists_match_single_checks_after_unit_limited_denies(): void
    {
        $this->denyUnit('director', 'personnel.profile.view', 'auto');        // scope all ตัด subtree แผนกช่างยนต์
        $this->denyUnit('multi', 'personnel.profile.view', 'hr');             // subtree จาก L2 ตัดงานบุคลากร
        $this->denyUnit('hr', 'personnel.profile.view', 'academic');          // งานบุคคล (all) ตัดฝ่ายวิชาการทั้งฝ่าย
        $this->denyUnit('teacher', 'personnel.profile.view', 'auto_program'); // own ในหน่วยที่ถูก deny = ไม่เห็นตนเอง
        $this->denyUnit('section_head', 'org.unit.view', 'academic');         // หน่วยงานใช้กติกาเดียวกัน
        $this->denyUnit('admin', 'org.unit.view', 'root');                    // deny ทั้งต้นไม้

        $this->assertSame([], $this->mismatches());

        // ยืนยันว่า deny มีผลจริง ไม่ใช่ตรงกันเพราะไม่มีอะไรเปลี่ยน
        $director = User::find($this->f->users['director']->id);
        $this->assertFalse($this->engine->allows($director, 'personnel.profile.view', $this->f->people['teacher']));
        $this->assertSame([], $this->engine->scopeQuery(User::find($this->f->users['teacher']->id), 'personnel.profile.view', Personnel::query())->pluck('id')->all());
        $this->assertSame([], $this->engine->scopeQuery(User::find($this->f->users['admin']->id), 'org.unit.view', OrgUnit::query())->pluck('id')->all());
        $this->assertNotContains($this->f->units['auto']->id,
            $this->engine->scopeQuery(User::find($this->f->users['section_head']->id), 'org.unit.view', OrgUnit::query())->pluck('id')->all());
    }
}
