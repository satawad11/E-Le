<?php

namespace Database\Factories;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'account_type' => 'personnel',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * บัญชีที่ผูกกับบุคลากร (CORE-03: บุคลากร ≠ บัญชีผู้ใช้)
     */
    public function withPersonnel(): static
    {
        return $this->state(fn (array $attributes) => [
            'personnel_id' => Personnel::factory(),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
