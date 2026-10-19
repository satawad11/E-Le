<?php

namespace Tests\Feature\Access;

use App\Authz\PermissionEngine;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Authz\Permission;
use App\Models\Authz\PermissionChangeRequest;
use App\Models\Authz\PermissionOverride;
use App\Models\Personnel;
use App\Models\PersonnelProfile;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Access\AccountLifecycleService;
use App\Services\Personnel\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * JML อัตโนมัติ (CORE-03 · R05-1) — ทุกเหตุการณ์ในตารางวงจรบัญชี + accounts:reconcile idempotent
 */
class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    private function newcomer(string $status, ?string $email = 'newcomer@dmtc.test'): Personnel
    {
        $personnel = Personnel::factory()->create(['personnel_code' => 'T900', 'status' => $status, 'started_at' => '2026-11-01']);
        if ($email !== null) {
            PersonnelProfile::create(['personnel_id' => $personnel->id, 'email_work' => $email]);
        }

        return $personnel;
    }

    private function lifecycle(): AccountLifecycleService
    {
        return app(AccountLifecycleService::class);
    }

    public function test_joiner_pending_personnel_gets_pending_account_then_active_with_setup_link(): void
    {
        $personnel = $this->newcomer('pending');

        $this->lifecycle()->reconcile();
        $user = User::where('personnel_id', $personnel->id)->firstOrFail();
        $this->assertSame(User::STATUS_PENDING, $user->status);
        $this->assertSame('T900', $user->username);
        $this->assertSame('newcomer@dmtc.test', $user->email);
        Notification::assertNotSentTo($user, AccountNotice::class);

        $personnel->update(['status' => 'active']); // event PersonnelStatusChanged

        $user->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('personnel:active', $user->status_reason);
        Notification::assertSentTo($user, AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::ACCOUNT_SETUP && str_contains((string) $n->actionUrl, '/reset-password?token='));
        $this->assertTrue(AuditLog::where('action', 'account.created')->where('resource_id', (string) $user->id)->exists());
        foreach (AuditLog::where('resource_id', (string) $user->id)->get() as $log) {
            $this->assertStringNotContainsString($user->password, json_encode([$log->before, $log->after], JSON_UNESCAPED_SLASHES), 'audit ไม่เก็บ hash รหัสผ่าน');
        }
    }

    public function test_joiner_without_work_email_is_reported_and_no_account_is_created(): void
    {
        $personnel = $this->newcomer('active', null);

        $tally = $this->lifecycle()->reconcile();

        $this->assertSame(1, $tally['missing_email'] ?? 0);
        $this->assertNull(User::where('personnel_id', $personnel->id)->first());
    }

    public function test_study_leave_suspends_account_revokes_tokens_and_return_reactivates_without_new_link(): void
    {
        $teacher = $this->f->users['teacher'];
        $token = $teacher->createToken('session')->plainTextToken;
        $this->withToken($token)->getJson('/api/user')->assertOk();

        $this->f->people['teacher']->update(['status' => 'study_leave']);

        $teacher->refresh();
        $this->assertSame(User::STATUS_SUSPENDED, $teacher->status);
        $this->assertSame('personnel:study_leave', $teacher->status_reason);
        $this->assertSame(0, $teacher->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();

        $this->f->people['teacher']->update(['status' => 'active']);

        $this->assertSame(User::STATUS_ACTIVE, $teacher->fresh()->status);
        Notification::assertNotSentTo($teacher, AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::ACCOUNT_SETUP);
    }

    public function test_personnel_suspension_maps_to_suspended_account(): void
    {
        $this->f->people['teacher']->update(['status' => 'suspended']);

        $this->assertSame(User::STATUS_SUSPENDED, $this->f->users['teacher']->fresh()->status);
    }

    public function test_jml_never_lifts_a_suspension_set_by_an_administrator(): void
    {
        $this->lifecycle()->suspend($this->f->users['teacher'], $this->f->users['admin'], 'สงสัยบัญชีถูกใช้โดยผู้อื่น');

        $this->f->people['teacher']->update(['status' => 'study_leave']);
        $this->f->people['teacher']->update(['status' => 'active']);
        $this->lifecycle()->reconcile();

        $teacher = $this->f->users['teacher']->fresh();
        $this->assertSame(User::STATUS_SUSPENDED, $teacher->status);
        $this->assertSame(AccountLifecycleService::REASON_MANUAL, $teacher->status_reason);
    }

    public function test_leaver_closes_everything_in_one_step_and_notifies_hr(): void
    {
        $teacher = $this->f->users['teacher'];
        $token = $teacher->createToken('session')->plainTextToken;
        $grant = $this->f->grant('teacher', 'audit.auditor', 'คำสั่ง 30/2569');
        $override = PermissionOverride::create([
            'user_id' => $teacher->id, 'permission_id' => Permission::where('name', 'personnel.report.view')->value('id'),
            'effect' => 'allow', 'scope' => 'subtree', 'reason' => 'ช่วยงานรายงาน', 'granted_by' => $this->f->users['admin']->id, 'expires_at' => '2027-03-31',
        ]);
        $request = PermissionChangeRequest::create([
            'target' => ['type' => 'user', 'user_id' => $teacher->id], 'diff' => ['action' => 'role_grant.create'],
            'requested_by' => $this->f->users['admin']->id, 'status' => 'pending',
        ]);
        $engine = app(PermissionEngine::class);
        $this->assertTrue($engine->allows($teacher, 'audit.log.view'));

        $this->f->people['teacher']->update(['status' => 'separated', 'separated_at' => '2026-10-15', 'separation_reason' => 'โอนย้ายไปสถานศึกษาอื่น']);

        $teacher->refresh();
        $this->assertSame(User::STATUS_SEPARATED, $teacher->status);
        $this->assertSame('2026-10-15', $teacher->separated_at->toDateString());
        $this->assertSame('โอนย้ายไปสถานศึกษาอื่น', $teacher->separation_reason);
        $this->assertSame(0, $teacher->tokens()->count());
        $this->assertNotNull($grant->fresh()->ended_at);
        $this->assertNotNull($override->fresh()->ended_at);
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(0, Assignment::where('personnel_id', $teacher->personnel_id)->effectiveOn(today()->toDateString())->count(), 'ตำแหน่งทุกตำแหน่งถูกปิด');
        $this->assertFalse($engine->allows($teacher, 'audit.log.view'));
        $this->assertFalse($engine->allows($teacher, 'personnel.profile.view', $this->f->people['teacher']));

        Notification::assertSentTo($this->f->users['hr'], AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::LEAVER_HANDOVER);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password'])->assertStatus(422);
    }

    public function test_leaver_is_idempotent_and_returning_staff_get_a_fresh_password(): void
    {
        $teacher = $this->f->users['teacher'];
        $this->f->people['teacher']->update(['status' => 'separated', 'separated_at' => '2026-10-15']);
        $hrNotices = Notification::sent($this->f->users['hr'], AccountNotice::class)->count();

        $this->lifecycle()->reconcile();
        $this->lifecycle()->reconcile();
        $this->assertSame($hrNotices, Notification::sent($this->f->users['hr'], AccountNotice::class)->count(), 'แจ้งงานบุคคลครั้งเดียว');

        $oldHash = $teacher->fresh()->password;
        $this->f->people['teacher']->update(['status' => 'active', 'separated_at' => null]);

        $teacher->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $teacher->status);
        $this->assertNull($teacher->separated_at);
        $this->assertNotSame($oldHash, $teacher->password, 'รหัสผ่านเดิมของผู้พ้นสภาพใช้ไม่ได้');
        Notification::assertSentTo($teacher, AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::ACCOUNT_SETUP);
    }

    public function test_mover_notifies_owner_and_rights_follow_the_new_assignment_immediately(): void
    {
        $teacher = $this->f->users['teacher'];
        $engine = app(PermissionEngine::class);
        $this->assertFalse($engine->allows($teacher, 'personnel.profile.view', $this->f->people['program_head']));

        app(AssignmentService::class)->add($this->f->people['teacher'], [
            'kind' => 'management', 'org_unit_id' => $this->f->units['pilot_program']->id, 'position_id' => $this->f->positions['program_head']->id,
            'effective_from' => '2026-10-01', 'order_number' => 'คำสั่ง 40/2569', 'order_date' => '2026-09-30',
        ]);

        Notification::assertSentTo($teacher, AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::ACCESS_CHANGED);
        $this->assertSame('subtree', app(PermissionEngine::class)->widestScope($teacher->fresh(), 'personnel.profile.view'));
    }

    public function test_reconcile_suspends_inactive_accounts_once_and_skips_break_glass_and_service(): void
    {
        $teacher = $this->f->users['teacher'];
        $teacher->forceFill(['last_login_at' => now()->subDays(91)])->saveQuietly();
        $recent = $this->f->users['program_head'];
        $recent->forceFill(['last_login_at' => now()->subDays(10)])->saveQuietly();
        $breakGlass = User::factory()->create(['is_break_glass' => true, 'last_login_at' => now()->subDays(200)]);
        $service = User::factory()->create(['account_type' => User::TYPE_SERVICE, 'last_login_at' => now()->subDays(200)]);
        User::query()->whereNull('last_login_at')->update(['last_login_at' => now()]);

        $first = $this->lifecycle()->reconcile();
        $second = $this->lifecycle()->reconcile();

        $this->assertSame(1, $first['inactive_suspended']);
        $this->assertSame(0, $second['inactive_suspended']);
        $this->assertSame(AccountLifecycleService::REASON_INACTIVE, $teacher->fresh()->status_reason);
        $this->assertSame(User::STATUS_ACTIVE, $recent->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $breakGlass->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $service->fresh()->status);
        Notification::assertSentTo($this->f->users['hr'], AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::INACTIVE_SUSPENDED);

        // inactive ไม่ถูกปลดด้วยสถานะบุคลากร — ต้องให้ผู้ดูแลเปิด
        $this->f->people['teacher']->update(['status' => 'study_leave']);
        $this->f->people['teacher']->update(['status' => 'active']);
        $this->assertSame(User::STATUS_SUSPENDED, $teacher->fresh()->status);
        $this->assertSame(AccountLifecycleService::REASON_INACTIVE, $teacher->fresh()->status_reason);
    }

    public function test_reconcile_releases_expired_locks_and_closes_expired_grants(): void
    {
        $teacher = $this->f->users['teacher'];
        $teacher->forceFill(['status' => User::STATUS_LOCKED, 'locked_until' => now()->subMinute(), 'failed_login_attempts' => 5])->saveQuietly();
        $expired = $this->f->grant('program_head', 'audit.auditor', 'คำสั่ง 31/2569', [], '2026-09-30');

        $tally = $this->lifecycle()->reconcile();

        $this->assertSame(1, $tally['unlocked']);
        $this->assertSame(User::STATUS_ACTIVE, $teacher->fresh()->status);
        $this->assertSame(0, $teacher->fresh()->failed_login_attempts);
        $this->assertSame(1, $tally['grants_expired']);
        $this->assertSame('หมดอายุตามคำสั่ง', $expired->fresh()->end_reason);

        $this->artisan('accounts:reconcile')->assertSuccessful();
    }
}
