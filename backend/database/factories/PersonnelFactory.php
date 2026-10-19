<?php

namespace Database\Factories;

use App\Models\Personnel;
use App\Models\PersonnelType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    protected $model = Personnel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'personnel_code' => fake()->unique()->numerify('P######'),
            'title' => 'นาย',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'personnel_type_id' => fn () => PersonnelType::firstOrCreate(
                ['code' => 'factory_type'],
                ['name' => 'ประเภททดสอบ', 'is_active' => true],
            )->id,
            'status' => 'active',
            'started_at' => now()->subYear()->toDateString(),
        ];
    }
}
