<?php

namespace Tests\Feature\Access;

use App\Models\Authz\PermissionAuditLog;
use App\Models\Authz\RoleGrant;
use App\Models\MfaCredential;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * `access:bootstrap` (Q11 · ADR-R-023) — ตั้งผู้ถือ system.admin / personnel.hr_officer คนแรกเท่านั้น
 */
class AccessBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    private function holders(string $code): int
    {
        return RoleGrant::whereNull('ended_at')->whereDate('expires_at', '>=', today()->toDateString())
            ->whereHas('template', fn ($q) => $q->where('code', $code))->count();
    }

    private function endFixtureGrant(string $persona): void
    {
        RoleGrant::where('user_id', $this->f->users[$persona]->id)->update(['ended_at' => now(), 'end_reason' => 'ทดสอบระบบว่าง']);
    }

    public function test_first_holder_is_set_once_with_audit_director_alert_and_forced_mfa_setup(): void
    {
        $this->endFixtureGrant('admin');
        $target = $this->f->users['auditor'];
        MfaCredential::create(['user_id' => $target->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);
        $target->createToken('session');
        $expires = today()->addDays(30)->toDateString();

        $this->artisan('access:bootstrap', ['template' => 'system.admin', 'user' => $target->username, '--order-number' => 'คำสั่ง 1/2570', '--expires' => $expires])
            ->assertSuccessful();

        $grant = RoleGrant::where('user_id', $target->id)->whereHas('template', fn ($q) => $q->where('code', 'system.admin'))->firstOrFail();
        $this->assertNull($grant->granted_by, 'ไม่มีผู้ใช้ในระบบเป็นผู้มอบ');
        $this->assertSame('คำสั่ง 1/2570', $grant->order_number);
        $this->assertSame($expires, $grant->expires_at->toDateString());

        $log = PermissionAuditLog::where('action', 'role_grant.bootstrapped')->where('subject_id', $grant->id)->firstOrFail();
        $this->assertNull($log->actor_user_id);
        $this->assertSame('artisan access:bootstrap', $log->after['via']);

        Notification::assertSentTo($this->f->users['director'], AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::BOOTSTRAP_GRANT);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNull($target->fresh()->confirmedMfa());

        // เข้าสู่ระบบครั้งแรกต้องตั้ง MFA ก่อนจึงได้ token
        $this->postJson('/api/login', ['login' => $target->email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('mfa_required', true)->assertJsonPath('enrolled', false)->assertJsonMissingPath('access_token');

        // ใช้ซ้ำไม่ได้เมื่อมีผู้ถืออยู่แล้ว
        $this->artisan('access:bootstrap', ['template' => 'system.admin', 'user' => $this->f->users['teacher']->username, '--order-number' => 'คำสั่ง 2/2570', '--expires' => $expires])
            ->assertFailed();
        $this->assertSame(1, $this->holders('system.admin'));
    }

    public function test_refused_while_an_active_holder_exists_and_allowed_once_holders_have_expired(): void
    {
        $args = ['template' => 'personnel.hr_officer', 'user' => $this->f->users['teacher']->username, '--order-number' => 'คำสั่ง 3/2570', '--expires' => today()->addDays(60)->toDateString()];

        $this->artisan('access:bootstrap', $args)->assertFailed();
        $this->assertFalse(RoleGrant::where('user_id', $this->f->users['teacher']->id)->exists());

        RoleGrant::where('user_id', $this->f->users['hr']->id)->update(['expires_at' => today()->subDay()->toDateString()]);
        $this->artisan('access:bootstrap', $args)->assertSuccessful();
        $this->assertSame(1, $this->holders('personnel.hr_officer'));
    }

    public function test_holder_whose_account_is_no_longer_active_does_not_block(): void
    {
        $this->f->users['admin']->forceFill(['status' => User::STATUS_SEPARATED])->saveQuietly();

        $this->artisan('access:bootstrap', ['template' => 'system.admin', 'user' => $this->f->users['auditor']->email, '--order-number' => 'คำสั่ง 4/2570', '--expires' => today()->addDays(10)->toDateString()])
            ->assertSuccessful();
    }

    public function test_order_number_expiry_template_and_target_are_mandatory(): void
    {
        $this->endFixtureGrant('admin');
        $ok = ['template' => 'system.admin', 'user' => $this->f->users['auditor']->username, '--order-number' => 'คำสั่ง 5/2570', '--expires' => today()->addDays(90)->toDateString()];

        $this->artisan('access:bootstrap', array_merge($ok, ['--order-number' => '']))->assertFailed();
        $this->artisan('access:bootstrap', array_diff_key($ok, ['--order-number' => 1]))->assertFailed();
        $this->artisan('access:bootstrap', array_diff_key($ok, ['--expires' => 1]))->assertFailed();
        $this->artisan('access:bootstrap', array_merge($ok, ['--expires' => today()->addDays(91)->toDateString()]))->assertFailed();
        $this->artisan('access:bootstrap', array_merge($ok, ['--expires' => today()->toDateString()]))->assertFailed();
        $this->artisan('access:bootstrap', array_merge($ok, ['--expires' => '31/12/2026']))->assertFailed();
        $this->artisan('access:bootstrap', array_merge($ok, ['template' => 'audit.auditor']))->assertFailed();

        $this->f->users['auditor']->forceFill(['status' => User::STATUS_SUSPENDED])->saveQuietly();
        $this->artisan('access:bootstrap', $ok)->assertFailed();
        $this->assertSame(0, $this->holders('system.admin'));

        $this->f->users['auditor']->forceFill(['status' => User::STATUS_ACTIVE])->saveQuietly();
        $this->artisan('access:bootstrap', $ok)->assertSuccessful(); // 90 วันพอดียังได้
        $this->assertSame(1, $this->holders('system.admin'));
    }
}
