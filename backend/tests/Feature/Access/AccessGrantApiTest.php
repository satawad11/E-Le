<?php

namespace Tests\Feature\Access;

use App\Authz\PermissionEngine;
use App\Models\Authz\PermissionAuditLog;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\PermissionOverride;
use App\Models\Authz\RoleGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * มอบ/ถอนสิทธิ์ผ่าน API (CORE-04 · R05-4) — grant_ceiling · two_person · maker_checker · not_own · วันหมดอายุบังคับ
 */
class AccessGrantApiTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    private function grantPayload(string $template, array $extra = []): array
    {
        return $extra + ['template_code' => $template, 'order_number' => 'คำสั่ง 60/2569', 'effective_from' => '2026-10-19', 'expires_at' => '2027-03-31'];
    }

    public function test_low_risk_template_is_granted_immediately_with_audit(): void
    {
        Sanctum::actingAs($this->f->users['admin']);

        $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/role-grants", $this->grantPayload('audit.auditor'))
            ->assertCreated()->assertJsonPath('template.code', 'audit.auditor');

        $grant = RoleGrant::where('user_id', $this->f->users['teacher']->id)->firstOrFail();
        $this->assertSame($this->f->users['admin']->id, $grant->granted_by);
        $this->assertTrue(app(PermissionEngine::class)->allows($this->f->users['teacher']->fresh(), 'audit.log.view'));
        $this->assertTrue(PermissionAuditLog::where('action', 'role_grant.created')->where('subject_id', $grant->id)->exists());
    }

    public function test_cannot_grant_more_than_one_holds(): void
    {
        Sanctum::actingAs($this->f->users['admin']); // ผู้ดูแลระบบไม่มีสิทธิ์บุคลากร (หลักการข้อ 8)

        $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/role-grants", $this->grantPayload('personnel.hr_officer'))
            ->assertForbidden();
        $message = $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/role-grants", $this->grantPayload('personnel.hr_officer'))->json('message');
        $this->assertStringContainsString('grant_ceiling', $message);
        $this->assertStringContainsString('personnel.profile.reveal (all)', $message);
        $this->assertStringNotContainsString('access.account.view', $message, 'สิทธิ์ที่ผู้มอบถืออยู่ไม่ถูกนับว่าเกิน');

        $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/overrides", [
            'permission' => 'personnel.profile.reveal', 'effect' => 'allow', 'scope' => 'all', 'reason' => 'ช่วยงานทะเบียน', 'expires_at' => '2027-03-31',
        ])->assertForbidden();

        $this->assertSame(0, RoleGrant::where('user_id', $this->f->users['teacher']->id)->count());
        $this->assertSame(0, PermissionOverride::where('user_id', $this->f->users['teacher']->id)->count());
        $this->assertSame(0, PermissionChangeRequest::count(), 'เกินเพดานไม่ถึงขั้นสร้างคำขอ');
    }

    public function test_ceiling_respects_the_granters_unit_scope(): void
    {
        // หัวหน้างานบุคลากร (subtree งานบุคลากร) ได้สิทธิ์มอบเพิ่มเฉพาะเพื่อทดสอบเพดานตามหน่วย
        $this->f->grant('section_head', 'system.admin', 'คำสั่ง 61/2569');
        Sanctum::actingAs($this->f->users['section_head']->fresh());

        $inUnit = ['permission' => 'personnel.profile.view', 'effect' => 'allow', 'scope' => 'subtree', 'reason' => 'ช่วยตรวจทะเบียน', 'expires_at' => '2027-03-31'];
        $this->postJson("/api/access/accounts/{$this->f->users['hr_staff']->uuid}/overrides", $inUnit)->assertCreated();
        $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/overrides", $inUnit)->assertForbidden(); // ครูอยู่นอกงานบุคลากร
        $this->postJson("/api/access/accounts/{$this->f->users['hr_staff']->uuid}/overrides", ['scope' => 'all'] + $inUnit)->assertForbidden();
    }

    public function test_nobody_grants_or_revokes_their_own_rights(): void
    {
        Sanctum::actingAs($this->f->users['admin']);

        $this->postJson("/api/access/accounts/{$this->f->users['admin']->uuid}/role-grants", $this->grantPayload('audit.auditor'))->assertForbidden();
        $own = RoleGrant::where('user_id', $this->f->users['admin']->id)->firstOrFail();
        $this->postJson("/api/access/role-grants/{$own->uuid}/end", ['reason' => 'ทดสอบ'])->assertForbidden();
        $this->assertNull($own->fresh()->ended_at);
    }

    public function test_high_risk_change_needs_a_second_person_and_the_requester_cannot_confirm_it(): void
    {
        Sanctum::actingAs($this->f->users['admin']);
        $response = $this->postJson("/api/access/accounts/{$this->f->users['auditor']->uuid}/role-grants", $this->grantPayload('system.admin', ['reason' => 'ผู้ดูแลสำรอง']))
            ->assertStatus(202)->assertJsonPath('change_request.status', 'pending');
        $uuid = $response->json('change_request.uuid');

        $this->assertFalse(RoleGrant::where('user_id', $this->f->users['auditor']->id)->whereHas('template', fn ($q) => $q->where('code', 'system.admin'))->exists(), 'ยังไม่มีผล');
        $this->assertFalse(app(PermissionEngine::class)->allows($this->f->users['auditor']->fresh(), 'access.grant.manage'));

        // ผู้ยื่นยืนยันเองไม่ได้ (maker_checker)
        $this->postJson("/api/access/change-requests/{$uuid}/approve")->assertForbidden();
        $this->assertSame('pending', PermissionChangeRequest::where('uuid', $uuid)->value('status'));

        // คนที่ไม่มี access.change.approve ยืนยันไม่ได้
        Sanctum::actingAs($this->f->users['hr']);
        $this->postJson("/api/access/change-requests/{$uuid}/approve")->assertForbidden();

        // ผอ. (ผู้ยืนยันคนที่สอง) ยืนยัน → มีผล
        Sanctum::actingAs($this->f->users['director']);
        $this->getJson('/api/access/change-requests')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
        $this->postJson("/api/access/change-requests/{$uuid}/approve", ['note' => 'ตามคำสั่ง 60/2569'])->assertOk()->assertJsonPath('status', 'approved');

        $grant = RoleGrant::where('user_id', $this->f->users['auditor']->id)->whereHas('template', fn ($q) => $q->where('code', 'system.admin'))->firstOrFail();
        $this->assertSame($this->f->users['admin']->id, $grant->granted_by);
        $this->assertSame($this->f->users['director']->id, $grant->approved_by);
        $this->assertTrue(app(PermissionEngine::class)->allows($this->f->users['auditor']->fresh(), 'access.grant.manage'));
        $this->assertTrue(PermissionAuditLog::where('action', 'role_grant.created')->whereNotNull('change_request_id')->exists());

        $this->postJson("/api/access/change-requests/{$uuid}/approve")->assertStatus(422); // พิจารณาแล้ว
    }

    public function test_second_person_cannot_confirm_a_request_that_grants_themselves(): void
    {
        Sanctum::actingAs($this->f->users['admin']);
        $uuid = $this->postJson("/api/access/accounts/{$this->f->users['director']->uuid}/overrides", [
            'permission' => 'access.account.manage', 'effect' => 'allow', 'scope' => 'all', 'reason' => 'ช่วยดูแลบัญชี', 'expires_at' => '2027-03-31',
        ])->assertStatus(202)->json('change_request.uuid');

        Sanctum::actingAs($this->f->users['director']);
        $this->postJson("/api/access/change-requests/{$uuid}/approve")->assertForbidden();
        $this->assertSame(0, PermissionOverride::where('user_id', $this->f->users['director']->id)->count());
    }

    public function test_requesters_ceiling_is_rechecked_when_the_request_is_confirmed(): void
    {
        Sanctum::actingAs($this->f->users['admin']);
        $uuid = $this->postJson("/api/access/accounts/{$this->f->users['auditor']->uuid}/role-grants", $this->grantPayload('system.admin'))
            ->assertStatus(202)->json('change_request.uuid');

        RoleGrant::where('user_id', $this->f->users['admin']->id)->update(['ended_at' => now()]);
        app(PermissionEngine::class)->flushAll();

        Sanctum::actingAs($this->f->users['director']);
        $this->postJson("/api/access/change-requests/{$uuid}/approve")->assertForbidden();
        $this->assertSame('pending', PermissionChangeRequest::where('uuid', $uuid)->value('status'));

        $this->postJson("/api/access/change-requests/{$uuid}/reject", ['note' => 'ผู้ยื่นพ้นหน้าที่'])->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function test_requester_can_withdraw_own_request_only(): void
    {
        Sanctum::actingAs($this->f->users['admin']);
        $uuid = $this->postJson("/api/access/accounts/{$this->f->users['auditor']->uuid}/role-grants", $this->grantPayload('system.admin'))->json('change_request.uuid');

        $this->f->grant('section_head', 'system.admin', 'คำสั่ง 62/2569');
        Sanctum::actingAs($this->f->users['section_head']->fresh());
        $this->postJson("/api/access/change-requests/{$uuid}/cancel")->assertForbidden();

        Sanctum::actingAs($this->f->users['admin']);
        $this->postJson("/api/access/change-requests/{$uuid}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_expiry_is_mandatory_for_role_grants_and_allow_overrides_but_not_for_denies(): void
    {
        Sanctum::actingAs($this->f->users['admin']);
        $teacher = $this->f->users['teacher'];

        $this->postJson("/api/access/accounts/{$teacher->uuid}/role-grants", ['expires_at' => null] + $this->grantPayload('audit.auditor'))
            ->assertStatus(422)->assertJsonValidationErrors('expires_at');
        $this->postJson("/api/access/accounts/{$teacher->uuid}/role-grants", $this->grantPayload('audit.auditor', ['order_number' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('order_number');
        $this->postJson("/api/access/accounts/{$teacher->uuid}/overrides", ['permission' => 'audit.log.view', 'effect' => 'allow', 'scope' => 'all', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->postJson("/api/access/accounts/{$teacher->uuid}/overrides", ['permission' => 'personnel.profile.view', 'effect' => 'deny', 'reason' => 'ส่วนได้เสีย'])
            ->assertCreated()->assertJsonPath('effect', 'deny');
        $this->assertFalse(app(PermissionEngine::class)->allows($teacher->fresh(), 'personnel.profile.view', $this->f->people['hr_staff']));
    }

    public function test_positional_templates_come_only_from_appointments(): void
    {
        Sanctum::actingAs($this->f->users['admin']);

        $this->postJson("/api/access/accounts/{$this->f->users['teacher']->uuid}/role-grants", $this->grantPayload('access.reviewer'))
            ->assertStatus(422)->assertJsonValidationErrors('template_code');
    }

    public function test_ending_a_grant_takes_effect_immediately(): void
    {
        $grant = $this->f->grant('teacher', 'audit.auditor', 'คำสั่ง 63/2569');
        $this->assertTrue(app(PermissionEngine::class)->allows($this->f->users['teacher'], 'audit.log.view'));

        Sanctum::actingAs($this->f->users['admin']);
        $this->postJson("/api/access/role-grants/{$grant->uuid}/end", ['reason' => 'พ้นหน้าที่'])->assertOk();

        $this->assertFalse(app(PermissionEngine::class)->allows($this->f->users['teacher']->fresh(), 'audit.log.view'));
        $this->assertTrue(PermissionAuditLog::where('action', 'role_grant.ended')->where('subject_id', $grant->id)->exists());
    }

    public function test_people_without_grant_rights_are_refused_by_the_route(): void
    {
        foreach (['teacher', 'hr', 'auditor', 'director'] as $persona) {
            Sanctum::actingAs($this->f->users[$persona]);
            $this->postJson("/api/access/accounts/{$this->f->users['program_head']->uuid}/role-grants", $this->grantPayload('audit.auditor'))->assertForbidden();
        }
    }
}
