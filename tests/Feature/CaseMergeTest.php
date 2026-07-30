<?php

use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Reporter;
use App\Services\CaseEngine\CaseMergeService;

it('merges source case into target case', function () {
    $target = AbuseCase::factory()->create([
        'case_number' => AbuseCase::generateCaseNumber(),
        'report_count' => 2,
    ]);
    $source = AbuseCase::factory()->create([
        'case_number' => AbuseCase::generateCaseNumber(),
        'report_count' => 3,
    ]);

    $reporter = Reporter::factory()->create();
    AbuseReport::factory()->count(3)->create([
        'case_id' => $source->id,
        'reporter_id' => $reporter->id,
    ]);

    $merger = app(CaseMergeService::class);
    $result = $merger->merge($target, $source);

    expect($result->reports()->count())->toBe(3);

    $source->refresh();
    expect($source->status)->toBe(CaseStatus::Closed);
    expect($source->metadata['merged_into'])->toBe($target->case_number);
});

it('prevents merging a case into itself', function () {
    // This is tested at the Livewire level, not service level
    // The service trusts caller validation
})->skip('Validated at Livewire layer');
