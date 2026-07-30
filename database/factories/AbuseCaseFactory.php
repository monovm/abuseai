<?php

namespace Database\Factories;

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use Illuminate\Database\Eloquent\Factories\Factory;

class AbuseCaseFactory extends Factory
{
    protected $model = AbuseCase::class;

    public function definition(): array
    {
        return [
            'case_number' => 'ABU-' . date('Y') . '-' . str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => CaseStatus::Open,
            'abuse_type' => fake()->randomElement(AbuseType::cases()),
            'severity_score' => 0,
            'severity_level' => SeverityLevel::Low,
            'target_ip' => fake()->ipv4(),
            'target_domain' => fake()->domainName(),
            'report_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
