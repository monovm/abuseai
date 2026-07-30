<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->companyEmail(),
            'company' => fake()->company(),
            'external_id' => (string) fake()->numberBetween(1000, 9999),
            'ip_addresses' => [fake()->ipv4(), fake()->ipv4()],
            'services' => [],
            'abuse_score' => fake()->randomFloat(2, 0, 100),
            'is_suspended' => false,
        ];
    }
}
