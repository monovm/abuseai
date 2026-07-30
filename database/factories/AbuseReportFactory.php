<?php

namespace Database\Factories;

use App\Enums\AbuseType;
use App\Models\AbuseReport;
use App\Models\Reporter;
use Illuminate\Database\Eloquent\Factories\Factory;

class AbuseReportFactory extends Factory
{
    protected $model = AbuseReport::class;

    public function definition(): array
    {
        return [
            'reporter_id' => Reporter::factory(),
            'raw_payload' => fake()->paragraph(3),
            'source' => fake()->randomElement(['feed', 'api', 'form', 'email']),
            'abuse_type' => fake()->randomElement(AbuseType::cases()),
            'target_ip' => fake()->ipv4(),
            'target_domain' => fake()->domainName(),
            'evidence' => fake()->paragraph(2),
            'reported_at' => now(),
        ];
    }
}
