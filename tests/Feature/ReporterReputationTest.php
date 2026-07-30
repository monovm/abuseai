<?php

use App\Models\Reporter;
use App\Services\ReporterReputationService;

it('increases reputation for valid abuse reports', function () {
    $reporter = Reporter::factory()->create(['reputation_score' => 50]);
    $service = app(ReporterReputationService::class);

    $service->adjustForReport($reporter, 'valid_abuse', 0.9);
    $reporter->refresh();

    expect((float) $reporter->reputation_score)->toBeGreaterThan(50);
});

it('decreases reputation for not_abuse reports', function () {
    $reporter = Reporter::factory()->create(['reputation_score' => 50]);
    $service = app(ReporterReputationService::class);

    $service->adjustForReport($reporter, 'not_abuse', 0.95);
    $reporter->refresh();

    expect((float) $reporter->reputation_score)->toBeLessThan(50);
});

it('caps reputation between 0 and 100', function () {
    $reporter = Reporter::factory()->create(['reputation_score' => 2]);
    $service = app(ReporterReputationService::class);

    $service->adjustForReport($reporter, 'false_positive');
    $reporter->refresh();

    expect((float) $reporter->reputation_score)->toBeGreaterThanOrEqual(0);
});

it('auto-blocks reporters with very low reputation', function () {
    $reporter = Reporter::factory()->create([
        'reputation_score' => 6,
        'report_count' => 15,
        'is_trusted' => false,
        'is_blocked' => false,
    ]);
    $service = app(ReporterReputationService::class);

    $service->adjustForReport($reporter, 'false_positive');
    $reporter->refresh();

    expect($reporter->is_blocked)->toBeTrue();
});

it('never auto-blocks trusted reporters', function () {
    $reporter = Reporter::factory()->create([
        'reputation_score' => 6,
        'report_count' => 15,
        'is_trusted' => true,
        'is_blocked' => false,
    ]);
    $service = app(ReporterReputationService::class);

    $service->adjustForReport($reporter, 'false_positive');
    $reporter->refresh();

    expect($reporter->is_blocked)->toBeFalse();
});
