<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Employee', 'guard_name' => 'web']);
        Role::create(['name' => 'Super Admin', 'guard_name' => 'web']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole('Employee');

        $response = $this->postJson('/api/login', [
            'email'       => $user->email,
            'password'    => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'user']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correctpassword'),
        ]);
        $user->assignRole('Employee');

        $response = $this->postJson('/api/login', [
            'email'       => $user->email,
            'password'    => 'wrongpassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('name', $user->name);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $response = $this->actingAs($user)->postJson('/api/logout');
        $response->assertStatus(200);
    }
}
