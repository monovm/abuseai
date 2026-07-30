<?php

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Events\CaseOpened;
use App\Events\CaseScored;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\CaseEngine\CaseCreatorService;
use Illuminate\Support\Facades\Event;

test('case creator creates new case for new report', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    // IP must be in our inventory
    IpAddress::factory()->create(['ip_address' => '10.10.10.10']);

    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Spam,
        'target_ip' => '10.10.10.10',
    ]);

    $creator = app(CaseCreatorService::class);
    $case = $creator->findOrCreateCase($report);

    expect($case)->toBeInstanceOf(AbuseCase::class);
    expect($case->case_number)->toStartWith('ABU-');
    expect($case->status)->toBe(CaseStatus::Open);
    expect($case->abuse_type)->toBe(AbuseType::Spam);
    expect($case->target_ip)->toBe('10.10.10.10');
    expect($case->report_count)->toBe(1);
    expect($report->fresh()->case_id)->toBe($case->id);

    Event::assertDispatched(CaseOpened::class);
    Event::assertDispatched(CaseScored::class);
});

test('case creator links duplicate report to existing case', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    IpAddress::factory()->create(['ip_address' => '20.20.20.20']);

    $reporter = Reporter::factory()->create(['reputation_score' => 50]);

    $report1 = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Phishing,
        'target_ip' => '20.20.20.20',
    ]);

    $creator = app(CaseCreatorService::class);
    $case1 = $creator->findOrCreateCase($report1);

    $report2 = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Phishing,
        'target_ip' => '20.20.20.20',
    ]);

    $case2 = $creator->findOrCreateCase($report2);

    expect($case1->id)->toBe($case2->id);
    expect($case2->report_count)->toBe(2);
});

test('case number is unique and formatted correctly', function () {
    $case = AbuseCase::factory()->create();

    expect($case->case_number)->toMatch('/^ABU-\d{4}-\d{5}$/');
});

test('severity score is calculated on case creation', function () {
    Event::fake([CaseOpened::class, CaseScored::class]);

    IpAddress::factory()->create(['ip_address' => '30.30.30.30']);

    $reporter = Reporter::factory()->create(['reputation_score' => 80]);

    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'abuse_type' => AbuseType::Malware,
        'target_ip' => '30.30.30.30',
    ]);

    $creator = app(CaseCreatorService::class);
    $case = $creator->findOrCreateCase($report);

    expect((float) $case->severity_score)->toBeGreaterThan(0);
});
