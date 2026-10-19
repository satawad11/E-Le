<?php

namespace Tests\Feature\Authz;

use App\Authz\Decision;
use App\Authz\PermissionEngine;
use App\Models\Authz\Module;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionOverride;
use App\Models\Personnel;
use App\Models\PersonnelChangeRequest;
use App\Models\User;
use App\Services\Personnel\AssignmentService;
use App\Services\Personnel\PersonnelPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * CORE-04 PermissionEngine (R04-3/R04-4) — Tests ขั้นต่ำ: หลายตำแหน่งข้ามฝ่าย L2 ไม่รั่วไป L3 · acting เฉพาะช่วงคำสั่ง
 * · override deny ชนะ · grant หมดอายุไม่มีผล · กฎกลาง · scopeQuery · explain · cache invalidation
 */
class PermissionEngineTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    private PermissionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CollegeFixture::build()->withAccessPersonas();
        $this->engine = app(PermissionEngine::class);
    }

    private function user(string $key): User
    {
        return $this->f->users[$key]->fresh();
    }

    public function test_level_two_rights_do_not_leak_into_the_level_three_subtree_of_the_same_person(): void
    {
        $multi = $this->user('multi');
        $teacher = $this->f->people['teacher'];   // สาขางานยานยนต์ ใต้แผนกช่างยนต์ (ที่เขาเป็นหัวหน้า L3)
        $hrStaff = $this->f->people['hr_staff'];  // งานบุคลากร ใต้ฝ่ายบริหารทรัพยากร (ที่เขาเป็นรองผอ. L2)

        // จากตำแหน่ง L3 (head): ดูประวัติใน subtree แผนกช่างยนต์ได้
        $this->assertTrue($this->engine->allows($multi, 'personnel.profile.view', $teacher));
        // สิทธิ์ของ L2 (deputy) — รายงานและข้อมูลชั้นลับ — ใช้ได้เฉพาะ subtree ฝ่ายบริหารทรัพยากร ไม่รั่วมาที่แผนกช่างยนต์
        $this->assertFalse($this->engine->allows($multi, 'personnel.report.view', $teacher));
        $this->assertFalse($this->engine->allows($multi, 'personnel.profile.view_confidential', $teacher));
        $this->assertTrue($this->engine->allows($multi, 'personnel.report.view', $hrStaff));
        $this->assertTrue($this->engine->allows($multi, 'personnel.profile.view_confidential', $hrStaff));

        // presenter ใช้ผลเดียวกัน
        $presented = app(PersonnelPresenter::class)->present($teacher->fresh(['profile']), $multi);
        $this->assertNotNull($presented['personnel']['personnel_code']);
        $this->assertContains('profile.birth_date', $presented['masked']);
    }

    public function test_decision_reports_the_source_assignment(): void
    {
        $decision = $this->engine->can($this->user('multi'), 'personnel.report.view', $this->f->people['hr_staff']);

        $this->assertTrue($decision->allowed);
        $this->assertSame('assignment', $decision->grant->sourceType);
        $this->assertSame($this->f->assignments['multi_deputy']->id, $decision->grant->sourceId);
        $this->assertStringContainsString('รองผู้อำนวยการ', $decision->grant->label);
    }

    public function test_acting_rights_exist_only_during_the_order(): void
    {
        $multi = $this->user('multi');
        $other = $this->f->people['deputy_academic']; // ฝ่ายวิชาการ — นอกขอบเขตเดิมทั้งสองตำแหน่ง

        $this->assertFalse($this->engine->allows($multi, 'access.change.approve'));
        $this->assertFalse($this->engine->allows($multi, 'personnel.profile.view', $other));

        Carbon::setTestNow(CollegeFixture::ACTING_FROM.' 09:00:00');
        $this->assertTrue($this->engine->allows($multi, 'access.change.approve'), 'รักษาการ ผอ. ได้สิทธิ์ของตำแหน่งต้นทาง');
        $this->assertTrue($this->engine->allows($multi, 'personnel.profile.view', $other));

        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->assertFalse($this->engine->allows($multi, 'access.change.approve'), 'หมดช่วงคำสั่งแล้วสิทธิ์หายทันที');
        Carbon::setTestNow();
    }

    public function test_override_deny_wins_over_every_source(): void
    {
        $director = $this->user('director');
        $this->assertTrue($this->engine->allows($director, 'personnel.profile.view', $this->f->people['teacher']));

        PermissionOverride::create([
            'user_id' => $director->id, 'permission_id' => Permission::where('name', 'personnel.profile.view')->value('id'),
            'effect' => 'deny', 'reason' => 'ระงับระหว่างสอบข้อเท็จจริง', 'granted_by' => $this->f->users['admin']->id,
        ]);

        $this->assertFalse($this->engine->allows($director, 'personnel.profile.view', $this->f->people['teacher']));
        $this->assertSame(Decision::NO_PERMISSION, $this->engine->can($director, 'personnel.profile.view')->reason);
    }

    public function test_override_deny_limited_to_a_unit_only_excludes_that_subtree(): void
    {
        $director = $this->user('director');
        PermissionOverride::create([
            'user_id' => $director->id, 'permission_id' => Permission::where('name', 'personnel.profile.view')->value('id'),
            'effect' => 'deny', 'org_unit_id' => $this->f->units['auto']->id, 'reason' => 'ส่วนได้เสีย', 'granted_by' => $this->f->users['admin']->id,
        ]);

        $this->assertFalse($this->engine->allows($director, 'personnel.profile.view', $this->f->people['teacher']));
        $this->assertTrue($this->engine->allows($director, 'personnel.profile.view', $this->f->people['hr_staff']));

        // ระดับรายการ: scope all ของ ผอ. ต้องไม่คืนบุคลากรในแผนกช่างยนต์ (รวมสาขางานยานยนต์)
        $listed = $this->engine->scopeQuery($director, 'personnel.profile.view', Personnel::query())->pluck('personnel_code')->all();
        foreach (['T001', 'T002', 'T003'] as $code) {
            $this->assertNotContains($code, $listed, "{$code} อยู่ในหน่วยที่ถูก deny");
        }
        $this->assertContains('S001', $listed);
        $this->assertContains('D001', $listed);

    }

    public function test_unit_limited_deny_never_hides_the_owners_own_record(): void
    {
        $head = $this->user('section_head'); // หัวหน้างานบุคลากร สังกัดงานบุคลากร
        PermissionOverride::create([
            'user_id' => $head->id, 'permission_id' => Permission::where('name', 'personnel.profile.view')->value('id'),
            'effect' => 'deny', 'org_unit_id' => $this->f->units['hr']->id, 'reason' => 'ส่วนได้เสีย', 'granted_by' => $this->f->users['admin']->id,
        ]);
        PermissionOverride::create([
            'user_id' => $head->id, 'permission_id' => Permission::where('name', 'personnel.profile.reveal')->value('id'),
            'effect' => 'deny', 'org_unit_id' => $this->f->units['hr']->id, 'reason' => 'ส่วนได้เสีย', 'granted_by' => $this->f->users['admin']->id,
        ]);
        $head = $head->fresh();
        $own = $this->f->people['section_head'];

        $this->assertTrue($this->engine->allows($head, 'personnel.profile.view', $own));
        $this->assertTrue($this->engine->allows($head, 'personnel.profile.reveal', $own));
        $this->assertFalse($this->engine->allows($head, 'personnel.profile.view', $this->f->people['hr_staff']), 'คนอื่นในหน่วยที่ถูก deny มองไม่เห็น');
        $this->assertSame(['S002'], $this->engine->scopeQuery($head, 'personnel.profile.view', Personnel::query())->pluck('personnel_code')->all());
    }

    public function test_allow_override_needs_an_expiry_and_expired_grants_have_no_effect(): void
    {
        $teacher = $this->user('teacher');
        $permissionId = Permission::where('name', 'personnel.report.view')->value('id');

        PermissionOverride::create(['user_id' => $teacher->id, 'permission_id' => $permissionId, 'effect' => 'allow', 'scope' => 'all',
            'reason' => 'ไม่มีวันหมดอายุ', 'granted_by' => $this->f->users['admin']->id]);
        $this->assertFalse($this->engine->allows($teacher, 'personnel.report.view'), 'allow ไม่มีวันหมดอายุไม่มีผล');

        PermissionOverride::create(['user_id' => $teacher->id, 'permission_id' => $permissionId, 'effect' => 'allow', 'scope' => 'all',
            'reason' => 'ช่วยงานรายงาน', 'granted_by' => $this->f->users['admin']->id, 'expires_at' => '2026-12-31']);
        $this->assertTrue($this->engine->allows($teacher->fresh(), 'personnel.report.view'));

        $grant = $this->f->grant('teacher', 'personnel.hr_officer', 'คำสั่ง 30/2569', [], '2026-06-30');
        $this->assertFalse($this->engine->allows($teacher->fresh(), 'personnel.import.manage'), 'role grant ที่หมดอายุแล้วไม่มีผล');
        $grant->update(['expires_at' => '2027-01-31']);
        $this->assertTrue($this->engine->allows($teacher->fresh(), 'personnel.import.manage'));
        $grant->update(['ended_at' => now(), 'end_reason' => 'ถอน']);
        $this->assertFalse($this->engine->allows($teacher->fresh(), 'personnel.import.manage'), 'ถอนแล้วไม่มีผล');
    }

    public function test_admin_and_auditor_sets_add_no_personnel_rights_and_admin_reveals_only_own_data(): void
    {
        foreach (['admin', 'auditor'] as $key) {
            $user = $this->user($key);
            $this->assertTrue($this->engine->allows($user, 'personnel.profile.view', $this->f->people[$key]), "{$key} ดูข้อมูลตนเองได้");
            $this->assertFalse($this->engine->allows($user, 'personnel.profile.view', $this->f->people['teacher']), "{$key} ไม่ได้สิทธิ์ดูผู้อื่นเพิ่มจากชุดสิทธิ์");
            $this->assertTrue($this->engine->allows($user, 'org.unit.view'), "{$key} ดูโครงสร้างองค์กรได้แบบบุคลากรทั่วไป");
        }

        $admin = $this->user('admin');
        $this->assertTrue($this->engine->allows($admin, 'personnel.profile.reveal', $this->f->people['admin']), 'เจ้าของข้อมูลเปิดดูข้อมูลของตนเองได้เสมอ (PDPA มาตรา 30)');
        $this->assertSame('own', $this->engine->widestScope($admin, 'personnel.profile.reveal'), 'deny ตัดเฉพาะ scope ที่กว้างกว่า own');
        $this->assertTrue($this->engine->allows($this->user('auditor'), 'personnel.profile.reveal', $this->f->people['auditor']), 'ผู้ตรวจสอบไม่มี deny');
        $this->assertTrue($this->engine->allows($admin, 'access.change.approve'), 'ยืนยันคำขอเปลี่ยนสิทธิ์ในฐานะผู้ดูแลคนที่สองได้ (ชุดสิทธิ์ให้เอง)');
        $this->assertTrue($this->engine->allows($admin, 'system.setting.manage'));
    }

    public function test_department_head_who_is_also_auditor_keeps_viewing_their_department(): void
    {
        $this->f->grant('section_head', 'audit.auditor', 'คำสั่ง 40/2569');
        $head = $this->user('section_head');

        $this->assertTrue($this->engine->allows($head, 'personnel.profile.view', $this->f->people['hr_staff']));
        $this->assertTrue($this->engine->allows($head, 'audit.log.view'));
        $this->assertSame(['S001', 'S002', 'S003'], $this->engine->scopeQuery($head, 'personnel.profile.view', Personnel::query())
            ->orderBy('personnel_code')->pluck('personnel_code')->all());
    }

    public function test_administrator_who_is_also_department_head_views_but_never_approves_or_reveals(): void
    {
        $this->f->grant('section_head', 'system.admin', 'คำสั่ง 41/2569');
        $head = $this->user('section_head');
        $staff = $this->f->people['hr_staff'];

        // ดูข้อมูลทั่วไปในแผนกตนได้ตามตำแหน่งหัวหน้า
        $this->assertTrue($this->engine->allows($head, 'personnel.profile.view', $staff));
        $this->assertNotNull(app(PersonnelPresenter::class)->present($staff->fresh(['profile']), $head)['personnel']['personnel_code']);

        // แต่ไม่อนุมัติ/ตรวจ และไม่เปิดดูข้อมูลอ่อนไหว แม้มีสิทธิ์จากแหล่งอื่นมาเพิ่ม
        PermissionOverride::create(['user_id' => $head->id, 'permission_id' => Permission::where('name', 'personnel.change_request.review')->value('id'),
            'effect' => 'allow', 'scope' => 'subtree', 'org_unit_id' => $this->f->units['hr']->id, 'reason' => 'ช่วยตรวจคำขอ',
            'granted_by' => $this->f->users['director']->id, 'expires_at' => '2026-12-31']);
        $this->f->grant('section_head', 'personnel.hr_officer', 'คำสั่ง 42/2569');
        $head = $head->fresh();

        $this->assertFalse($this->engine->allows($head, 'personnel.change_request.review'));
        $this->assertFalse($this->engine->allows($head, 'personnel.profile.reveal', $staff), 'ไม่เปิดดูข้อมูลอ่อนไหวของผู้อื่นแม้ได้ all จากชุดสิทธิ์งานบุคคล');
        $this->assertTrue($this->engine->allows($head, 'personnel.profile.reveal', $this->f->people['section_head']), 'ข้อมูลของตนเองยังเปิดดูได้');
        $this->assertTrue($this->engine->allows($head, 'personnel.profile.edit', $staff), 'สิทธิ์ที่ไม่ใช่การอนุมัติ/เปิดดูข้อมูลอ่อนไหวยังใช้ได้');
    }

    public function test_disabled_module_and_inactive_account_deny_everything(): void
    {
        $hr = $this->user('hr');
        Module::where('code', 'personnel')->update(['enabled' => false]);
        $this->engine->flushAll();
        $this->assertSame(Decision::MODULE_DISABLED, $this->engine->can($hr, 'personnel.profile.view')->reason);

        Module::where('code', 'personnel')->update(['enabled' => true]);
        $this->engine->flushAll();
        $hr->forceFill(['status' => User::STATUS_SUSPENDED])->saveQuietly();
        $this->assertSame(Decision::INACTIVE_ACCOUNT, $this->engine->can($hr->fresh(), 'personnel.profile.view')->reason);
        $this->assertSame(Decision::UNKNOWN_PERMISSION, $this->engine->can($this->user('director'), 'leave.approval.approve')->reason);
    }

    public function test_central_rules(): void
    {
        $hr = $this->user('hr');
        $hrPersonnel = $this->f->people['hr_staff'];

        // not_own: งานบุคคลแต่งตั้งตนเองไม่ได้
        $this->assertFalse($this->engine->allows($hr, 'personnel.assignment.manage', $hrPersonnel));
        $this->assertSame('not_own', $this->engine->can($hr, 'personnel.assignment.manage', $hrPersonnel)->rule);
        $this->assertTrue($this->engine->allows($hr, 'personnel.assignment.manage', $this->f->people['teacher']));

        // maker_checker: ผู้ยื่นคำขอพิจารณาเองไม่ได้ (แม้คำขอเป็นของผู้อื่น)
        $request = PersonnelChangeRequest::create(['personnel_id' => $this->f->people['teacher']->id, 'requested_by' => $hr->id,
            'entity' => 'personnel', 'payload' => ['last_name' => 'x'], 'status' => 'pending']);
        $this->assertSame('maker_checker', $this->engine->can($hr, 'personnel.change_request.review', $request)->rule);

        // hr_field_guard: แก้ฟิลด์ hr_only ของตนเองไม่ได้ แต่ของผู้อื่นได้
        $context = ['entity' => 'personnel', 'fields' => ['status']];
        $this->assertSame('hr_field_guard', $this->engine->can($hr, 'personnel.profile.edit', $hrPersonnel, $context)->rule);
        $this->assertTrue($this->engine->allows($hr, 'personnel.profile.edit', $this->f->people['teacher'], $context));
    }

    public function test_grant_ceiling_and_two_person(): void
    {
        $sectionHead = $this->user('section_head');
        $hr = $this->user('hr');

        $this->assertTrue($this->engine->canGrant($sectionHead, 'personnel.profile.view', 'subtree', $this->f->units['hr']));
        $this->assertFalse($this->engine->canGrant($sectionHead, 'personnel.profile.view', 'all'), 'ให้เกินขอบเขตตนไม่ได้');
        $this->assertFalse($this->engine->canGrant($sectionHead, 'personnel.profile.view', 'subtree', $this->f->units['auto']), 'ให้นอกหน่วยตนไม่ได้');
        $this->assertFalse($this->engine->canGrant($sectionHead, 'personnel.import.manage', 'all'), 'ให้สิทธิ์ที่ตนไม่มีไม่ได้');
        $this->assertTrue($this->engine->canGrant($hr, 'personnel.profile.view', 'all'));

        $this->assertTrue($this->engine->requiresSecondPerson('access.template.manage'));
        $this->assertTrue($this->engine->requiresSecondPerson(['org.unit.view', 'personnel.import.manage']));
        $this->assertFalse($this->engine->requiresSecondPerson('org.unit.view'));
    }

    public function test_scope_query_filters_lists_by_the_union_of_sources(): void
    {
        $codes = fn (string $user) => $this->engine->scopeQuery($this->user($user), 'personnel.profile.view', Personnel::query())
            ->orderBy('personnel_code')->pluck('personnel_code')->all();

        // multi: ตนเอง (own) + subtree แผนกช่างยนต์ (L3) + subtree ฝ่ายบริหารทรัพยากร (L2)
        $this->assertSame(['S001', 'S002', 'S003', 'S004', 'T001', 'T002', 'T003'], $codes('multi'));
        $this->assertSame(['T002'], $codes('teacher'));
        $this->assertSame(Personnel::count(), count($codes('director')));
        $this->assertSame(['S004'], $codes('auditor'), 'ผู้ตรวจสอบเห็นเฉพาะตนเอง');
        $this->assertSame([], $this->engine->scopeQuery(User::factory()->create(), 'personnel.profile.view', Personnel::query())->pluck('id')->all());
    }

    public function test_explain_lists_each_decision_step(): void
    {
        $explain = $this->engine->explain($this->user('section_head'), 'personnel.profile.view', $this->f->people['teacher']);

        $this->assertFalse($explain['allowed']);
        $this->assertSame(Decision::OUT_OF_SCOPE, $explain['reason']);
        $this->assertSame(['identity', 'grants', 'scope', 'result'], array_column($explain['steps'], 'step'));

        $allowed = $this->engine->explain($this->user('hr'), 'personnel.change_request.review', PersonnelChangeRequest::make([
            'personnel_id' => $this->f->people['teacher']->id, 'requested_by' => $this->f->users['teacher']->id,
        ]));
        $this->assertTrue($allowed['allowed']);
        $this->assertSame(['identity', 'grants', 'scope', 'rule', 'rule', 'result'], array_column($allowed['steps'], 'step'));
    }

    public function test_cache_is_invalidated_when_an_assignment_ends(): void
    {
        $sectionHead = $this->user('section_head');
        $this->assertTrue($this->engine->allows($sectionHead, 'personnel.profile.view', $this->f->people['hr_staff']));

        app(AssignmentService::class)->end($this->f->assignments['section_head'], today()->subDay(), 'ย้ายไปรับตำแหน่งอื่น');

        $this->assertFalse($this->engine->allows($sectionHead->fresh(), 'personnel.profile.view', $this->f->people['hr_staff']));
    }
}
