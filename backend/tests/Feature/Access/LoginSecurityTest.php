<?php

namespace Tests\Feature\Access;

use App\Models\KnownDevice;
use App\Models\LoginEvent;
use App\Models\PersonnelProfile;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Access\TotpService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * login events · ล็อกบัญชี · อุปกรณ์ใหม่ · break-glass · บัญชีระบบ · token ของบัญชีที่ไม่ active (CORE-03 · R05-3)
 *
 * แทน tests/Legacy AuthTest, AuthenticationHardeningTest (ย้ายเจตนาเดิมมาบนแบบจำลองใหม่)
 */
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    private function teacher(): User
    {
        return $this->f->users['teacher'];
    }

    public function test_successful_login_records_event_and_returns_expiring_token(): void
    {
        $response = $this->postJson('/api/login', ['login' => $this->f->people['teacher']->personnel_code, 'password' => 'password', 'device_id' => 'laptop-1'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 480 * 60);

        $token = PersonalAccessToken::findToken($response->json('access_token'));
        $this->assertNotNull($token->expires_at);
        $this->assertTrue(LoginEvent::where('user_id', $this->teacher()->id)->where('result', 'success')->exists());
        $this->assertNotNull($this->teacher()->fresh()->last_login_at);
    }

    public function test_five_bad_passwords_lock_the_account_for_thirty_minutes(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'wrong'])->assertStatus(423);

        $teacher = $this->teacher()->fresh();
        $this->assertSame(User::STATUS_LOCKED, $teacher->status);
        $this->assertSame('failed_logins', $teacher->status_reason);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $teacher->locked_until->timestamp, 5);
        $this->assertSame(5, LoginEvent::where('user_id', $teacher->id)->where('result', 'bad_password')->count());

        // รหัสถูกระหว่างล็อกก็ไม่ผ่าน
        $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password'])->assertStatus(423);
        $this->assertSame(2, LoginEvent::where('user_id', $teacher->id)->where('result', 'locked')->count(), 'ตอนล็อก 1 + พยายามระหว่างล็อก 1');

        $this->travel(31)->minutes();
        $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password'])->assertOk();
        $this->assertSame(User::STATUS_ACTIVE, $teacher->fresh()->status);
        $this->assertSame(0, $teacher->fresh()->failed_login_attempts);
    }

    public function test_successful_login_resets_the_failure_counter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'wrong'])->assertStatus(422);

        $this->assertSame(User::STATUS_ACTIVE, $this->teacher()->fresh()->status);
    }

    public function test_unknown_account_is_logged_without_user_and_national_id_is_masked(): void
    {
        $this->postJson('/api/login', ['login' => 'nobody@dmtc.test', 'password' => 'x'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'ข้อมูลการเข้าสู่ระบบไม่ถูกต้อง');
        $this->postJson('/api/login', ['login' => '1103700012345', 'password' => 'x'])->assertStatus(422);

        $this->assertTrue(LoginEvent::whereNull('user_id')->where('username_attempted', 'nobody@dmtc.test')->exists());
        $this->assertFalse(LoginEvent::where('username_attempted', '1103700012345')->exists(), 'ไม่เก็บเลข 13 หลักเต็ม');
        $this->assertTrue(LoginEvent::where('username_attempted', '1••••••••••45')->exists());
    }

    public function test_new_device_sends_an_email_once_and_remember_extends_trust_to_thirty_days(): void
    {
        $teacher = $this->teacher();

        $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password', 'device_id' => 'phone-1', 'remember_device' => true])->assertOk();
        Notification::assertSentToTimes($teacher, AccountNotice::class, 1);
        $this->assertTrue(LoginEvent::where('user_id', $teacher->id)->where('result', 'new_device')->exists());
        $device = KnownDevice::where('user_id', $teacher->id)->firstOrFail();
        $this->assertSame(hash('sha256', 'id:phone-1'), $device->device_hash, 'เก็บเฉพาะ hash');
        $this->assertTrue($device->trusted_until->greaterThan(now()->addDays(29)));

        $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password', 'device_id' => 'phone-1'])->assertOk();
        Notification::assertSentToTimes($teacher, AccountNotice::class, 1);

        $this->postJson('/api/login', ['login' => $teacher->email, 'password' => 'password', 'device_id' => 'kiosk-9'])->assertOk();
        Notification::assertSentToTimes($teacher, AccountNotice::class, 2);
    }

    public function test_break_glass_login_alerts_director_and_auditor_every_time(): void
    {
        $breakGlass = User::factory()->create(['username' => 'breakglass1', 'is_break_glass' => true]);
        $this->f->grant('director', 'audit.auditor', 'คำสั่ง 50/2569'); // ผอ. เป็นผู้ตรวจสอบด้วย — ต้องได้อีเมลฉบับเดียว

        $challenge = $this->postJson('/api/login', ['login' => 'breakglass1', 'password' => 'password'])->assertOk()
            ->assertJsonPath('mfa_required', true)->json('challenge');
        $this->assertNotNull($challenge, 'break-glass ต้องผ่าน MFA');

        $secret = $this->postJson('/api/login/mfa/setup', ['challenge' => $challenge])->json('secret');
        $this->postJson('/api/login/mfa/setup/confirm', ['challenge' => $challenge, 'code' => app(TotpService::class)->currentCode($secret)])->assertOk();

        Notification::assertSentTo($this->f->users['director'], AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::BREAK_GLASS_USED);
        Notification::assertSentTo($this->f->users['auditor'], AccountNotice::class, fn (AccountNotice $n) => $n->kind === AccountNotice::BREAK_GLASS_USED);
        Notification::assertSentToTimes($this->f->users['director'], AccountNotice::class, 1);
    }

    public function test_service_and_inactive_accounts_cannot_log_in_interactively(): void
    {
        $service = User::factory()->create(['username' => 'svc-attendance', 'account_type' => User::TYPE_SERVICE]);
        $this->postJson('/api/login', ['login' => 'svc-attendance', 'password' => 'password'])->assertStatus(422);
        $this->assertTrue(LoginEvent::where('user_id', $service->id)->where('result', 'blocked')->exists());

        foreach ([User::STATUS_PENDING, User::STATUS_SUSPENDED, User::STATUS_SEPARATED] as $status) {
            $this->teacher()->forceFill(['status' => $status])->saveQuietly();
            $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'password'])->assertStatus(422);
        }
    }

    public function test_separated_account_cannot_use_an_old_token_even_if_the_row_survives(): void
    {
        $token = $this->teacher()->createToken('session')->plainTextToken;
        $this->withToken($token)->getJson('/api/user')->assertOk();

        // เปลี่ยนสถานะแบบไม่ผ่าน observer (เช่น แก้ฐานข้อมูลตรง) — token ยังอยู่ แต่ใช้ไม่ได้
        $this->teacher()->forceFill(['status' => User::STATUS_SEPARATED])->saveQuietly();
        $this->assertSame(1, $this->teacher()->tokens()->count());
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/me/permissions')->assertUnauthorized();
    }

    public function test_refresh_rotates_the_token_and_keeps_its_mfa_status(): void
    {
        $plain = $this->teacher()->createToken('session:mfa', ['*'], now()->addHour())->plainTextToken;

        $new = $this->withToken($plain)->postJson('/api/refresh-token')->assertOk()->json('access_token');

        $this->assertNull(PersonalAccessToken::findToken($plain));
        $this->assertSame('session:mfa', PersonalAccessToken::findToken($new)->name);
    }

    public function test_changing_password_requires_current_password_and_revokes_every_token(): void
    {
        $token = $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'password'])->json('access_token');
        $this->teacher()->createToken('other-device');

        $this->withToken($token)->postJson('/api/me/password', ['current_password' => 'wrong', 'password' => 'a-much-longer-passphrase', 'password_confirmation' => 'a-much-longer-passphrase'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->withToken($token)->postJson('/api/me/password', ['current_password' => 'password', 'password' => 'a-much-longer-passphrase', 'password_confirmation' => 'a-much-longer-passphrase'])
            ->assertOk();

        $this->assertTrue(Hash::check('a-much-longer-passphrase', $this->teacher()->fresh()->password));
        $this->assertSame(0, $this->teacher()->tokens()->count());
    }

    public function test_forgot_password_does_not_reveal_whether_the_account_exists(): void
    {
        $known = $this->postJson('/api/forgot-password', ['email' => $this->teacher()->email])->assertOk()->json('message');
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'ghost@dmtc.test'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($this->teacher(), ResetPassword::class);
    }

    public function test_password_reset_link_sets_a_new_password_and_revokes_existing_tokens(): void
    {
        $teacher = $this->teacher();
        $teacher->createToken('session');
        $token = Password::broker()->createToken($teacher);

        $this->postJson('/api/reset-password', ['token' => $token, 'email' => $teacher->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422);
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => $teacher->email, 'password' => 'new-long-passphrase', 'password_confirmation' => 'new-long-passphrase'])
            ->assertOk();

        $this->assertTrue(Hash::check('new-long-passphrase', $teacher->fresh()->password));
        $this->assertSame(0, $teacher->tokens()->count());
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => $teacher->email, 'password' => 'another-long-pass', 'password_confirmation' => 'another-long-pass'])
            ->assertStatus(422); // ลิงก์ใช้ได้ครั้งเดียว
    }

    public function test_login_by_personnel_code_and_national_id_follows_adr_r013(): void
    {
        PersonnelProfile::create(['personnel_id' => $this->teacher()->personnel_id, 'national_id' => '1103700012346']);

        $this->postJson('/api/login', ['login' => 'T002', 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => '1103700012346', 'password' => 'password'])->assertStatus(422);

        config(['auth.allow_national_id_login' => true]);
        $this->postJson('/api/login', ['login' => '1103700012346', 'password' => 'password'])->assertOk();
    }

    public function test_logout_revokes_the_current_token_only(): void
    {
        $token = $this->postJson('/api/login', ['login' => $this->teacher()->email, 'password' => 'password'])->json('access_token');
        $this->teacher()->createToken('other-device');

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertSame(1, $this->teacher()->tokens()->count());
    }
}
