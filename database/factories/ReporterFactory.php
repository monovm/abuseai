<?php

namespace Database\Factories;

use App\Models\Reporter;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReporterFactory extends Factory
{
    protected $model = Reporter::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'type' => fake()->randomElement(['feed', 'api', 'form', 'email', 'manual']),
            'reputation_score' => fake()->randomFloat(2, 0, 100),
            'report_count' => fake()->numberBetween(0, 500),
            'is_trusted' => fake()->boolean(30),
            'is_blocked' => false,
        ];
    }
}
