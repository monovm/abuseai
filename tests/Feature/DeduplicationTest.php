<?php

use App\Enums\AbuseType;
use App\Models\AbuseReport;
use App\Models\Reporter;
use App\Services\Ingestion\DeduplicationService;

test('first report is not flagged as duplicate', function () {
    $reporter = Reporter::factory()->create();

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Spam,
        'target_ip' => '50.50.50.50',
    ]);

    $dedup = new DeduplicationService();

    // Without Redis, dedup should gracefully allow through
    expect($dedup->isDuplicate($report))->toBeFalse();
});
