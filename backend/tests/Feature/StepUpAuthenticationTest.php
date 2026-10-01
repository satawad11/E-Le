<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use App\Support\ProductionEnvironmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * การยืนยันตัวตนซ้ำ (step-up) และ TOTP — ย้ายมาจาก SarabanSignatureAndStepUpTest
 * เมื่อถอดโมดูลสารบรรณ เพราะด่านนี้เป็นของระบบยืนยันตัวตน ไม่ใช่ของการลงนามหนังสือ
 *
 * เรื่องที่ตรึงไว้: รหัสผ่าน/OTP ที่ถูกต้องเท่านั้นที่เปิดช่วงยืนยันตัวตน · OTP ใช้ไม่ได้จนกว่า
 * จะลงทะเบียนแอปจริง (ความลับคำนวณจาก APP_KEY ได้ — ช่องข้ามด่านที่ปิดใน commit 0847ad4)
 * · ความลับไม่ออกให้โทเคนที่ไม่กรอกรหัสผ่าน · ต่ออายุโทเคนไม่ยืดช่วง · production ปิดด่านไม่ได้
 */
class StepUpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.step_up.enabled' => true, 'auth.step_up.window_minutes' => 10]);
    }

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)])->fresh();
    }

    /**
     * ทำงานด้วยโทเคนจริง — `actingAs()` ใช้ TransientToken ที่ไม่มีแถวเก็บเวลายืนยันตัวตน
     * และล้าง guard ก่อน เพราะ application instance เดียวถูกใช้ซ้ำในชุดทดสอบ
     */
    private function actAs(User $user, ?string $authenticatedAt): void
    {
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test-token');
        $token->accessToken->forceFill(['last_authenticated_at' => $authenticatedAt])->save();

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    private function lastAuthenticatedAt(User $user): ?Carbon
    {
        return $user->tokens()->latest('id')->first()->last_authenticated_at;
    }

    public function test_confirming_the_password_opens_the_window(): void
    {
        $user = $this->user();
        $this->actAs($user, now()->subHour()->toDateTimeString());

        $this->postJson('/api/auth/confirm-password', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('valid_for_minutes', 10);

        $this->assertTrue($this->lastAuthenticatedAt($user)->gt(now()->subMinute()));
    }

    public function test_a_wrong_password_does_not_open_the_window(): void
    {
        $user = $this->user();
        $this->actAs($user, now()->subHour()->toDateTimeString());

        $this->postJson('/api/auth/confirm-password', ['password' => 'ไม่ใช่รหัสผ่าน'])->assertStatus(422);

        $this->assertTrue($this->lastAuthenticatedAt($user)->lt(now()->subMinutes(30)));
    }

    public function test_an_enrolled_authenticator_code_opens_the_window(): void
    {
        $user = $this->user();
        $user->forceFill(['totp_confirmed_at' => now()->subDay()])->save();
        $this->actAs($user, now()->subHour()->toDateTimeString());

        $totp = app(TotpService::class);

        $this->postJson('/api/auth/confirm-password', ['totp_code' => $totp->generateCode($totp->secretForUser($user))])
            ->assertOk()
            ->assertJsonPath('valid_for_minutes', 10);
    }

    /**
     * ความลับ TOTP คำนวณจาก APP_KEY ได้เสมอ — ถ้าไม่บังคับให้ลงทะเบียนก่อน
     * ผู้ที่ขโมยโทเคนไปจะคำนวณรหัสเองแล้วผ่านด่านยืนยันตัวตนซ้ำได้ทันที
     */
    public function test_totp_is_rejected_until_the_user_has_enrolled_the_authenticator(): void
    {
        $user = $this->user();
        $this->actAs($user, now()->subHour()->toDateTimeString());

        $totp = app(TotpService::class);

        $this->postJson('/api/auth/confirm-password', ['totp_code' => $totp->generateCode($totp->secretForUser($user))])
            ->assertStatus(422);
    }

    public function test_totp_secret_is_not_handed_out_without_the_password(): void
    {
        $this->actAs($this->user(), now()->subHour()->toDateTimeString());

        $this->postJson('/api/auth/totp-setup', [])->assertStatus(422);
        $this->postJson('/api/auth/totp-setup', ['password' => 'ไม่ใช่รหัสผ่าน'])
            ->assertStatus(422)
            ->assertJsonMissingPath('secret');
    }

    public function test_enrolling_the_authenticator_unlocks_totp_step_up(): void
    {
        $user = $this->user();
        $this->actAs($user, now()->subHour()->toDateTimeString());

        $secret = $this->postJson('/api/auth/totp-setup', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('enrolled', false)
            ->json('secret');

        $code = app(TotpService::class)->generateCode($secret);

        $this->postJson('/api/auth/totp-setup', ['password' => self::PASSWORD, 'totp_code' => $code])
            ->assertOk()
            ->assertJsonPath('enrolled', true);

        $this->assertNotNull($user->fresh()->totp_confirmed_at);
        $this->postJson('/api/auth/confirm-password', ['totp_code' => $code])->assertOk();
    }

    /** ต่ออายุโทเคนต้องไม่ยืดช่วงยืนยันตัวตน มิฉะนั้นด่านนี้ไม่มีความหมาย */
    public function test_refreshing_the_token_carries_the_original_confirmation_time(): void
    {
        $user = $this->user();
        $original = now()->subMinutes(9);
        $this->actAs($user, $original->toDateTimeString());

        $this->postJson('/api/refresh-token')->assertOk();

        $this->assertSame($original->format('Y-m-d H:i'), $this->lastAuthenticatedAt($user->fresh())->format('Y-m-d H:i'));
    }

    public function test_production_refuses_to_start_with_step_up_switched_off(): void
    {
        $problems = ProductionEnvironmentGuard::problems('production', false, [
            'app_key' => 'base64:AAAA',
            'step_up_enabled' => false,
        ]);

        $this->assertNotEmpty(array_filter($problems, fn (string $problem) => str_contains($problem, 'REQUIRE_STEP_UP_REAUTH')));
    }
}
