<?php

namespace Tests\Feature\Access;

use App\Models\LoginEvent;
use App\Models\MfaCredential;
use App\Models\User;
use App\Services\Access\AuthenticationService;
use App\Services\Access\MfaPolicy;
use App\Services\Access\TotpService;
use App\Services\Personnel\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\Fixtures\CollegeFixture;
use Tests\TestCase;

/**
 * MFA TOTP + รหัสสำรอง (CORE-03 · R05-2) — บังคับถูกกลุ่ม · ไม่มี token ก่อนผ่าน MFA · รหัสใช้ซ้ำไม่ได้
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private CollegeFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->f = CollegeFixture::build()->withAccessPersonas();
    }

    private function totp(): TotpService
    {
        return app(TotpService::class);
    }

    /** ตั้ง MFA ที่ยืนยันแล้วให้ผู้ใช้ — คืน secret */
    private function enroll(User $user): string
    {
        $secret = $this->totp()->generateSecret();
        MfaCredential::create(['user_id' => $user->id, 'type' => 'totp', 'secret' => $secret, 'confirmed_at' => now()]);

        return $secret;
    }

    /** รหัสของช่วงเวลาถัดไป — ใช้แทนการรอ 30 วินาทีเมื่อรหัสของช่วงปัจจุบันถูกใช้ไปแล้ว (กันใช้ซ้ำ) */
    private function nextCode(User $user, string $secret): string
    {
        return (new Google2FA)->oathTotp($secret, (int) $user->confirmedMfa()->last_timestep + 1);
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/login', ['login' => $user->email, 'password' => 'password', 'device_id' => 'device-'.$user->id]);
    }

    public function test_mfa_is_required_for_l1_to_l3_hr_admin_and_break_glass_only(): void
    {
        $policy = app(MfaPolicy::class);
        $expected = [
            'director' => true,         // L1
            'deputy_academic' => true,  // L2
            'section_head' => true,     // L3 หัวหน้างาน
            'multi' => true,            // L3 หัวหน้าแผนก + L2 รองฯ
            'hr' => true,               // ชุดสิทธิ์งานบุคคล
            'admin' => true,            // ชุดสิทธิ์ผู้ดูแลระบบ
            'program_head' => false,    // L4
            'teacher' => false,         // L5
            'auditor' => false,         // ผู้ตรวจสอบ (ไม่อยู่ในกลุ่มบังคับ)
        ];
        $actual = [];
        foreach ($expected as $persona => $_) {
            $actual[$persona] = $policy->requires($this->f->users[$persona]);
        }
        $this->assertSame($expected, $actual);

        $breakGlass = User::factory()->create(['is_break_glass' => true]);
        $this->assertTrue($policy->requires($breakGlass));
        $this->assertContains('ชุดสิทธิ์งานบุคคล (ทะเบียนประวัติ)', $policy->reasons($this->f->users['hr']));
    }

    public function test_acting_l1_only_requires_mfa_during_the_order(): void
    {
        $this->travelTo('2026-11-05');
        $this->assertTrue(app(MfaPolicy::class)->reasons($this->f->users['multi']) !== []);
        $this->assertContains('รักษาการผู้อำนวยการ (L1)', app(MfaPolicy::class)->reasons($this->f->users['multi']));

        $this->travelTo('2026-11-20');
        $this->assertNotContains('รักษาการผู้อำนวยการ (L1)', app(MfaPolicy::class)->reasons($this->f->users['multi']));
    }

    public function test_required_user_gets_no_token_until_mfa_is_set_up_and_confirmed(): void
    {
        $head = $this->f->users['section_head'];

        $response = $this->login($head)->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('enrolled', false)
            ->assertJsonMissingPath('access_token');
        $challenge = $response->json('challenge');
        $this->assertSame(0, $head->tokens()->count());

        $setup = $this->postJson('/api/login/mfa/setup', ['challenge' => $challenge])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);
        $this->assertStringContainsString('<svg', $setup['qr_svg']);

        $this->postJson('/api/login/mfa/setup/confirm', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);

        $done = $this->postJson('/api/login/mfa/setup/confirm', ['challenge' => $challenge, 'code' => $this->totp()->currentCode($setup['secret'])])
            ->assertOk()->assertJsonPath('mfa_verified', true)->json();
        $this->assertCount(8, $done['recovery_codes']);
        $this->assertSame(AuthenticationService::TOKEN_SESSION_MFA, PersonalAccessToken::findToken($done['access_token'])->name);
        $this->assertNotNull($head->fresh()->confirmedMfa());

        $this->withToken($done['access_token'])->getJson('/api/me/permissions')->assertOk();
    }

    public function test_enrolled_user_passes_totp_once_and_the_same_code_cannot_be_replayed(): void
    {
        $director = $this->f->users['director'];
        $secret = $this->enroll($director);

        $challenge = $this->login($director)->assertOk()->assertJsonPath('enrolled', true)->json('challenge');
        $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);
        $this->assertTrue(LoginEvent::where('user_id', $director->id)->where('result', 'mfa_failed')->exists());

        $code = $this->totp()->currentCode($secret);
        $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'code' => $code])->assertOk()->assertJsonStructure(['access_token', 'user']);
        $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'code' => $code])->assertStatus(422); // challenge ใช้ไปแล้ว

        $again = $this->login($director)->json('challenge');
        $this->postJson('/api/login/mfa', ['challenge' => $again, 'code' => $code])->assertStatus(422); // รหัสเดิมใช้ซ้ำไม่ได้
    }

    public function test_recovery_code_works_exactly_once(): void
    {
        $director = $this->f->users['director'];
        $this->enroll($director);
        $codes = $this->totp()->issueRecoveryCodes($director->confirmedMfa());

        $challenge = $this->login($director)->json('challenge');
        $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'recovery_code' => strtolower($codes[0])])
            ->assertOk()->assertJsonPath('recovery_codes_remaining', 7);

        $challenge = $this->login($director)->json('challenge');
        $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'recovery_code' => $codes[0]])->assertStatus(422);
    }

    public function test_user_outside_required_groups_logs_in_without_mfa(): void
    {
        $this->login($this->f->users['teacher'])->assertOk()
            ->assertJsonPath('mfa_verified', false)
            ->assertJsonStructure(['access_token', 'user' => ['uuid', 'permission_names', 'mfa' => ['enabled', 'required']]]);
    }

    public function test_token_without_mfa_is_refused_once_the_user_becomes_required(): void
    {
        $teacher = $this->f->users['teacher'];
        $token = $this->login($teacher)->json('access_token');
        $this->withToken($token)->getJson('/api/me/permissions')->assertOk();

        // ได้รับแต่งตั้งเป็นรองผู้อำนวยการ (L2) ระหว่างที่ยังล็อกอินอยู่
        app(AssignmentService::class)->add($this->f->people['teacher'], [
            'kind' => 'management', 'org_unit_id' => $this->f->units['strategy']->id, 'position_id' => $this->f->positions['deputy']->id,
            'effective_from' => '2026-10-01', 'order_number' => 'คำสั่ง 41/2569', 'order_date' => '2026-09-30',
        ]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me/permissions')->assertForbidden()->assertJsonPath('code', 'mfa_required');
        $this->withToken($token)->postJson('/api/logout')->assertOk();
    }

    public function test_mfa_check_is_fail_closed_for_every_way_of_authenticating(): void
    {
        config(['auth.mfa.allow_transient_in_tests' => false]);
        $director = $this->f->users['director'];

        // session ของ guard web (Sanctum ให้ TransientToken) — ไม่มี token MFA จึงถูกปฏิเสธ
        $this->actingAs($director, 'web')->getJson('/api/me/permissions')->assertForbidden()->assertJsonPath('code', 'mfa_required');
        $this->app['auth']->forgetGuards();

        // token จำลองของ Sanctum::actingAs ก็ไม่ผ่านเมื่อไม่ได้เปิดข้อยกเว้นของเทสต์
        Sanctum::actingAs($director);
        $this->getJson('/api/me/permissions')->assertForbidden()->assertJsonPath('code', 'mfa_required');
        $this->app['auth']->forgetGuards();

        // token จริงที่ไม่ได้ผ่าน MFA และ token ชื่อ session:mfa ปลอมที่ไม่มีแถวไม่ได้ช่วย
        $plain = $director->createToken('session')->plainTextToken;
        $this->withToken($plain)->getJson('/api/me/permissions')->assertForbidden();
        $this->app['auth']->forgetGuards();

        $mfa = $director->createToken(AuthenticationService::TOKEN_SESSION_MFA)->plainTextToken;
        $this->withToken($mfa)->getJson('/api/me/permissions')->assertOk();

        // ผู้ที่ไม่อยู่ในกลุ่มบังคับใช้ session ได้ตามปกติ
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->f->users['teacher'], 'web')->getJson('/api/me/permissions')->assertOk();
    }

    public function test_five_wrong_mfa_codes_lock_the_account(): void
    {
        $director = $this->f->users['director'];
        $this->enroll($director);

        $challenge = $this->login($director)->json('challenge');
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(423);

        $this->assertSame(User::STATUS_LOCKED, $director->fresh()->status);
        $this->login($director)->assertStatus(423);
    }

    public function test_self_service_mfa_setup_and_cannot_disable_when_required(): void
    {
        $teacher = $this->f->users['teacher'];
        $token = $this->login($teacher)->json('access_token');

        $secret = $this->withToken($token)->postJson('/api/me/security/mfa/setup')->assertOk()->json('secret');
        $this->withToken($token)->postJson('/api/me/security/mfa/confirm', ['code' => $this->totp()->currentCode($secret)])
            ->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->withToken($token)->getJson('/api/me/security')->assertOk()
            ->assertJsonPath('mfa.enabled', true)->assertJsonPath('mfa.required', false);

        // ผู้ที่เปิด MFA เองก็ต้องผ่าน MFA ทุกครั้ง
        $this->login($teacher)->assertJsonPath('mfa_required', true)->assertJsonPath('enrolled', true);

        $this->withToken($token)->postJson('/api/me/security/mfa/disable', ['code' => $this->nextCode($teacher, $secret)])->assertOk();
        $this->assertNull($teacher->fresh()->confirmedMfa());

        // ผู้ที่ถูกบังคับปิดไม่ได้
        $hr = $this->f->users['hr'];
        $hrSecret = $this->enroll($hr);
        $challenge = $this->login($hr)->json('challenge');
        $hrToken = $this->postJson('/api/login/mfa', ['challenge' => $challenge, 'code' => $this->totp()->currentCode($hrSecret)])->json('access_token');
        $this->withToken($hrToken)->postJson('/api/me/security/mfa/disable', ['code' => $this->nextCode($hr, $hrSecret)])->assertStatus(422)->assertJsonValidationErrors('mfa');
        $this->assertNotNull($hr->fresh()->confirmedMfa());
    }

    public function test_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        $director = $this->f->users['director'];
        $secret = $this->enroll($director);
        $codes = $this->totp()->issueRecoveryCodes($director->confirmedMfa());

        $row = DB::table('mfa_credentials')->where('user_id', $director->id)->first();
        $this->assertStringNotContainsString($secret, $row->secret);
        $this->assertStringNotContainsString($codes[0], (string) $row->recovery_codes);
        $this->assertStringNotContainsString($codes[0], json_encode(decrypt($row->recovery_codes, false)));
    }
}
