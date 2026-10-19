<?php

namespace Tests\Legacy\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_an_account_revokes_all_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('first');
        $user->createToken('second');

        $user->is_active = false;
        $user->save();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_changing_a_password_revokes_all_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('first');

        $user->password = 'new-password-123';
        $user->save();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_account_is_temporarily_locked_after_five_failed_logins(): void
    {
        $user = User::factory()->create(['email' => 'locked@example.com', 'password' => 'correct-password']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(429);

        RateLimiter::clear('login-account:'.hash('sha256', $user->email));
    }

    public function test_issued_token_has_an_expiration_time(): void
    {
        config(['sanctum.expiration' => 480]);
        $user = User::factory()->create(['email' => 'expiry@example.com', 'password' => 'correct-password']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->assertNotNull($user->tokens()->sole()->expires_at);
    }

    public function test_refresh_rotates_the_current_token(): void
    {
        config(['sanctum.expiration' => 480]);
        $user = User::factory()->create();
        $old = $user->createToken('old', ['*'], now()->addMinutes(30));

        $response = $this->withToken($old->plainTextToken)
            ->postJson('/api/refresh-token')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'expires_in']);

        $this->assertNotSame($old->plainTextToken, $response->json('access_token'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $old->accessToken->id]);
    }

    public function test_password_can_be_reset_and_existing_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $user->createToken('existing');
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $this->assertTrue(password_verify('new-secure-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_forgot_password_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/forgot-password', ['email' => 'known@example.com'])
            ->assertOk()
            ->json('message');
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'unknown@example.com'])
            ->assertOk()
            ->json('message');

        $this->assertSame($known, $unknown);
    }
}
