<?php

namespace Database\Seeders;

use App\Models\Reporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ReporterSeeder extends Seeder
{
    public function run(): void
    {
        // Trusted feed reporters
        Reporter::updateOrCreate(
            ['email' => 'abuseipdb@feeds.abuseai'],
            ['name' => 'AbuseIPDB', 'type' => 'feed', 'is_trusted' => true, 'reputation_score' => 90, 'report_count' => 1250],
        );

        Reporter::updateOrCreate(
            ['email' => 'spamcop@feeds.abuseai'],
            ['name' => 'SpamCop', 'type' => 'feed', 'is_trusted' => true, 'reputation_score' => 85, 'report_count' => 830],
        );

        Reporter::updateOrCreate(
            ['email' => 'spamhaus@feeds.abuseai'],
            ['name' => 'Spamhaus', 'type' => 'feed', 'is_trusted' => true, 'reputation_score' => 95, 'report_count' => 420],
        );

        Reporter::updateOrCreate(
            ['email' => 'google_fbl@feeds.abuseai'],
            ['name' => 'Google FBL', 'type' => 'feed', 'is_trusted' => true, 'reputation_score' => 88, 'report_count' => 670],
        );

        Reporter::updateOrCreate(
            ['email' => 'microsoft_fbl@feeds.abuseai'],
            ['name' => 'Microsoft FBL', 'type' => 'feed', 'is_trusted' => true, 'reputation_score' => 87, 'report_count' => 310],
        );

        // API reporters with keys
        Reporter::updateOrCreate(
            ['email' => 'security@partner-isp.com'],
            [
                'name' => 'Partner ISP Security Team',
                'type' => 'api',
                'api_key' => Hash::make('partner-isp-api-key-2026'),
                'is_trusted' => true,
                'reputation_score' => 80,
                'report_count' => 145,
            ],
        );

        Reporter::updateOrCreate(
            ['email' => 'abuse@acme.example'],
            [
                'name' => 'ACME Abuse',
                'type' => 'api',
                'api_key' => Hash::make('acme-api-key-2026'),
                'is_trusted' => true,
                'reputation_score' => 92,
                'report_count' => 210,
            ],
        );

        // Regular form/email reporters
        Reporter::factory()->count(15)->create([
            'type' => 'form',
            'reputation_score' => fn () => fake()->randomFloat(2, 30, 80),
        ]);

        Reporter::factory()->count(5)->create([
            'type' => 'email',
            'reputation_score' => fn () => fake()->randomFloat(2, 40, 75),
        ]);

        // One blocked reporter
        Reporter::factory()->create([
            'name' => 'Spam Bot Reporter',
            'email' => 'bot@spam-reports.invalid',
            'type' => 'form',
            'is_blocked' => true,
            'reputation_score' => 2.0,
            'report_count' => 500,
        ]);
    }
}
