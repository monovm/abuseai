<?php

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Reporter;
use App\Services\CaseEngine\SeverityScorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('CSAM always returns 100', function () {
    $case = AbuseCase::factory()->create([
        'abuse_type' => AbuseType::Csam,
        'report_count' => 1,
    ]);

    $scorer = new SeverityScorerService();
    $score = $scorer->calculate($case);

    expect($score)->toBe(100.0);
});

test('score is capped at 100', function () {
    $reporter = Reporter::factory()->create(['reputation_score' => 100]);

    $case = AbuseCase::factory()->create([
        'abuse_type' => AbuseType::Ddos,
        'report_count' => 1000,
    ]);

    // Create many reports to push score high
    AbuseReport::factory()->count(10)->create([
        'case_id' => $case->id,
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Ddos,
    ]);

    $scorer = new SeverityScorerService();
    $score = $scorer->calculate($case);

    expect($score)->toBeLessThanOrEqual(100.0);
});

test('higher report count increases score', function () {
    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $case1 = AbuseCase::factory()->create([
        'abuse_type' => AbuseType::Spam,
        'report_count' => 1,
    ]);
    AbuseReport::factory()->create([
        'case_id' => $case1->id,
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Spam,
    ]);

    $case5 = AbuseCase::factory()->create([
        'abuse_type' => AbuseType::Spam,
        'report_count' => 5,
    ]);
    AbuseReport::factory()->count(5)->create([
        'case_id' => $case5->id,
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Spam,
    ]);

    $scorer = new SeverityScorerService();

    expect($scorer->calculate($case5))->toBeGreaterThan($scorer->calculate($case1));
});

test('scoreAndUpdate persists score and level', function () {
    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $case = AbuseCase::factory()->create([
        'abuse_type' => AbuseType::Phishing,
        'report_count' => 3,
        'severity_score' => 0,
        'severity_level' => SeverityLevel::Low,
    ]);

    AbuseReport::factory()->count(3)->create([
        'case_id' => $case->id,
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Phishing,
    ]);

    $scorer = new SeverityScorerService();
    $updated = $scorer->scoreAndUpdate($case);

    expect((float) $updated->severity_score)->toBeGreaterThan(0);
    expect($updated->severity_level)->toBeInstanceOf(SeverityLevel::class);
});
