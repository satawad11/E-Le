<?php

namespace Tests\Feature\Authz;

use App\Authz\PermissionEngine;
use App\Models\PersonnelChangeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * PLAN §8.3 — persona ทุกคอลัมน์ × กลุ่มเมนูของ core (ส่วนบุคคล · ข้อมูลบุคลากร · บัญชีและสิทธิ์ · ตั้งค่าระบบ)
 * และ Permission Matrix (persona × permission → scope ที่กว้างที่สุด)
 *
 * แถว ระบบการลา / ลงเวลา / OT / สารบรรณ ของตาราง 8.3 ทดสอบใน R-08 เมื่อโมดูลเหล่านั้นมี manifest
 */
class PersonaMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * เมนูที่ต้องเห็น (รหัสเมนูตาม manifest) ต่อ persona — ตรงตาม PLAN §8.3
     * ส่วนบุคคล: งานรออนุมัติแสดงเฉพาะผู้มีสิทธิ์ *.*.approve / *.*.review (หัวหน้าจะได้เมื่อโมดูลลาเข้ามาใน R-08)
     *
     * @return array<string, array{string, array<string, array<int, string>>}>
     */
    public static function personaMenus(): array
    {
        $personal = ['core.home', 'core.me', 'core.notifications'];
        $withInbox = ['core.home', 'core.inbox', 'core.me', 'core.notifications'];

        return [
            'บุคลากร L5' => ['teacher', [
                'core' => $personal,
                'personnel' => ['org.structure'],
            ]],
            'หัวหน้า L4' => ['program_head', [
                'core' => $personal,
                'personnel' => ['personnel.list', 'org.structure'],
                'access' => ['access.reviews'],
            ]],
            'หัวหน้า L3' => ['section_head', [
                'core' => $personal,
                'personnel' => ['personnel.list', 'org.structure'],
                'access' => ['access.reviews'],
            ]],
            'รองฯ L2' => ['deputy_academic', [
                'core' => $personal,
                'personnel' => ['personnel.list', 'org.structure', 'personnel.reports'],
                'access' => ['access.reviews'],
            ]],
            'ผอ. L1' => ['director', [
                'core' => $withInbox,
                'personnel' => ['personnel.list', 'org.structure', 'personnel.reports'],
                'access' => ['access.change_requests', 'access.reviews'],
            ]],
            'งานบุคคล' => ['hr', [
                'core' => $withInbox,
                'personnel' => ['personnel.list', 'personnel.change_requests', 'org.structure', 'org.positions', 'org.assignments', 'personnel.reports'],
                'access' => ['access.accounts'],
                'system' => ['system.workflows'],
            ]],
            'ผู้ดูแลระบบ' => ['admin', [
                'core' => $withInbox,
                'access' => ['access.accounts', 'access.roles', 'access.modules', 'access.change_requests', 'access.reviews', 'access.audit'],
                'system' => ['system.modules', 'system.workflows', 'system.documents', 'system.notifications', 'system.settings'],
            ]],
            'ผู้ตรวจสอบ' => ['auditor', [
                'core' => $personal,
                'access' => ['access.accounts', 'access.roles', 'access.reviews', 'access.audit'],
            ]],
        ];
    }

    /** @param  array<string, array<int, string>>  $expected */
    #[DataProvider('personaMenus')]
    public function test_menu_matches_plan_8_3(string $persona, array $expected): void
    {
        $f = CollegeFixture::build()->withAccessPersonas();
        Sanctum::actingAs($f->users[$persona]);

        $groups = $this->getJson('/api/me/menu')->assertOk()->json('groups');
        $actual = collect($groups)->mapWithKeys(fn ($g) => [$g['code'] => array_column($g['items'], 'code')])->all();

        $this->assertSame($expected, $actual);

        // ข้อมูลของฉัน มีเมนูย่อยประวัติการเข้าถึงเสมอ
        $me = collect($groups[0]['items'])->firstWhere('code', 'core.me');
        $this->assertSame(['core.me.access_history'], array_column($me['children'], 'code'));
    }

    public function test_menu_names_follow_the_scope(): void
    {
        $f = CollegeFixture::build()->withAccessPersonas();
        $name = function (string $persona) use ($f) {
            Sanctum::actingAs($f->users[$persona]);
            $items = collect($this->getJson('/api/me/menu')->json('groups'))->flatMap(fn ($g) => $g['items']);

            return $items->firstWhere('code', 'personnel.list')['name'] ?? null;
        };

        $this->assertSame('บุคลากรงานบุคลากร', $name('section_head'));
        $this->assertSame('บุคลากรสาขางานยานยนต์', $name('program_head'));
        $this->assertSame('บุคลากรฝ่ายวิชาการ', $name('deputy_academic'));
        $this->assertSame('รายชื่อบุคลากร', $name('director'));
        $this->assertSame('รายชื่อบุคลากร (2 หน่วย)', $name('multi'));
    }

    public function test_change_request_badge_counts_only_items_in_scope(): void
    {
        $f = CollegeFixture::build()->withAccessPersonas();
        PersonnelChangeRequest::create(['personnel_id' => $f->people['teacher']->id, 'requested_by' => $f->users['teacher']->id,
            'entity' => 'personnel', 'payload' => ['last_name' => 'ใหม่'], 'status' => 'pending']);

        Sanctum::actingAs($f->users['hr']);
        $items = collect($this->getJson('/api/me/menu')->json('groups'))->flatMap(fn ($g) => $g['items']);

        $this->assertSame(1, $items->firstWhere('code', 'personnel.change_requests')['badge']);
    }

    /**
     * Permission Matrix — scope ที่กว้างที่สุดต่อ persona (null = ไม่มีสิทธิ์)
     * คอลัมน์: L5, L4, L3, L2, L1, HR, admin, auditor
     */
    private const MATRIX = [
        'personnel.profile.view' => ['own', 'subtree', 'subtree', 'subtree', 'all', 'all', 'own', 'own'],
        'personnel.profile.view_confidential' => ['own', 'own', 'own', 'subtree', 'all', 'all', 'own', 'own'],
        'personnel.profile.reveal' => ['own', 'own', 'own', 'own', 'own', 'all', 'own', 'own'],
        'personnel.profile.edit' => [null, null, null, null, null, 'all', null, null],
        'personnel.change_request.create' => ['own', 'own', 'own', 'own', 'own', 'own', 'own', 'own'],
        'personnel.change_request.review' => [null, null, null, null, null, 'all', null, null],
        'personnel.assignment.manage' => [null, null, null, null, null, 'all', null, null],
        'personnel.report.view' => [null, null, null, 'subtree', 'all', 'all', null, null],
        'personnel.import.manage' => [null, null, null, null, null, 'all', null, null],
        'org.unit.view' => ['all', 'all', 'all', 'all', 'all', 'all', null, null],
        'org.unit.manage' => [null, null, null, null, null, 'all', null, null],
        'org.position.manage' => [null, null, null, null, null, 'all', null, null],
        'access.account.view' => [null, null, null, null, null, 'all', 'all', 'all'],
        'access.role.view' => [null, null, null, null, null, null, 'all', 'all'],
        'access.template.manage' => [null, null, null, null, null, null, 'all', null],
        'access.grant.manage' => [null, null, null, null, null, null, 'all', null],
        'access.change.approve' => [null, null, null, null, 'all', null, 'all', null],
        'access.review.confirm' => [null, 'subtree', 'subtree', 'subtree', 'subtree', null, null, null],
        'access.review.manage' => [null, null, null, null, null, null, 'all', 'all'],
        'audit.log.view' => [null, null, null, null, null, null, 'all', 'all'],
        'system.module.manage' => [null, null, null, null, null, null, 'all', null],
        'system.setting.manage' => [null, null, null, null, null, null, 'all', null],
        'workflow.definition.manage' => [null, null, null, null, null, 'all', 'all', null],
    ];

    private const PERSONAS = ['teacher', 'program_head', 'section_head', 'deputy_academic', 'director', 'hr', 'admin', 'auditor'];

    public function test_permission_matrix(): void
    {
        $f = CollegeFixture::build()->withAccessPersonas();
        $engine = app(PermissionEngine::class);

        $mismatches = [];
        foreach (self::MATRIX as $permission => $expected) {
            foreach (self::PERSONAS as $i => $persona) {
                $actual = $engine->widestScope($f->users[$persona]->fresh(), $permission);
                if ($actual !== $expected[$i]) {
                    $mismatches[] = "{$permission} × {$persona}: คาด ".var_export($expected[$i], true).' ได้ '.var_export($actual, true);
                }
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function test_me_permissions_lists_scope_and_source(): void
    {
        $f = CollegeFixture::build()->withAccessPersonas();
        Sanctum::actingAs($f->users['section_head']);

        $grants = collect($this->getJson('/api/me/permissions')->assertOk()->json('grants'));
        $view = $grants->where('permission', 'personnel.profile.view')->firstWhere('scope', 'subtree');

        $this->assertSame(['งานบุคลากร'], $view['units']);
        $this->assertSame('assignment', $view['source']['type']);
        $this->assertStringContainsString('หัวหน้างาน', $view['source']['label']);
        $this->assertContains('access.review.confirm', $this->getJson('/api/me/permissions')->json('names'));
    }
}
