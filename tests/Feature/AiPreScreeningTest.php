<?php

use App\Events\ReportReceived;
use App\Jobs\AI\TriageReport;
use App\Listeners\HandleReportReceived;
use App\Models\AbuseReport;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\AI\TriageAiService;
use App\Services\CaseEngine\CaseCreatorService;
use App\Services\Ingestion\DeduplicationService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('skips case creation when AI classifies report as not_abuse', function () {
    $reporter = Reporter::factory()->create();
    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'target_ip' => '1.2.3.4',
        'abuse_type' => 'other',
        'evidence' => 'Thank you for your clarification regarding port 25.',
    ]);

    // Mock triage service to return not_abuse. The listener calls
    // triageAll() (combined classify+noise+iocs round-trip), not the
    // older single classify() method.
    $triage = Mockery::mock(TriageAiService::class);
    $triage->shouldReceive('triageAll')->once()->andReturn([
        'classification' => [
            'type' => 'not_abuse',
            'confidence' => 0.95,
            'is_abuse_report' => false,
            'summary' => 'Customer inquiry about port 25 policy',
        ],
        'noise' => ['noise_score' => 0.95],
        'iocs' => ['ips' => [], 'domains' => [], 'urls' => []],
    ]);
    app()->instance(TriageAiService::class, $triage);

    $listener = app(HandleReportReceived::class);
    $listener->handle(new ReportReceived($report));

    $report->refresh();
    expect($report->metadata['flagged_as_not_abuse'])->toBeTrue();
    // ai_noise_score is a decimal column; Eloquent returns it as a string
    // ("1.00") not a float. Use loose equality.
    expect((float) $report->ai_noise_score)->toBe(1.0);
    expect($report->case_id)->toBeNull();
});

it('skips case creation for SEO / paid guest posting marketing outreach', function () {
    $reporter = Reporter::factory()->create();
    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'target_ip' => null,
        'target_domain' => 'london-post.co.uk',
        'abuse_type' => 'spam',
        'evidence' => "Dear Sir,\nI hope you are doing well. We offer paid guest posting on authoritative sites with dofollow backlinks and fast Google indexing. Sample: https://london-post.co.uk/sample-article/. Pricing on request.",
    ]);

    $triage = Mockery::mock(TriageAiService::class);
    $triage->shouldReceive('triageAll')->once()->andReturn([
        'classification' => [
            'type' => 'not_abuse',
            'confidence' => 0.97,
            'is_abuse_report' => false,
            'summary' => 'Unsolicited paid guest-posting / SEO backlink marketing pitch — not an abuse report.',
        ],
        'noise' => ['noise_score' => 1.0, 'is_not_abuse' => true, 'reason' => 'SEO marketing solicitation'],
        'iocs' => ['target_ips' => [], 'target_domains' => [], 'target_urls' => [], 'reporter_ips' => [], 'reporter_domains' => []],
    ]);
    app()->instance(TriageAiService::class, $triage);

    $listener = app(HandleReportReceived::class);
    $listener->handle(new ReportReceived($report));

    $report->refresh();
    expect($report->metadata['flagged_as_not_abuse'])->toBeTrue();
    expect((float) $report->ai_noise_score)->toBe(1.0);
    expect($report->case_id)->toBeNull();
});

it('creates case when AI classifies report as valid abuse', function () {
    $reporter = Reporter::factory()->create();
    $ip = IpAddress::factory()->create(['ip_address' => '10.0.0.1', 'status' => 'active']);
    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'target_ip' => '10.0.0.1',
        'abuse_type' => 'spam',
        'evidence' => 'Spam emails from your IP 10.0.0.1',
    ]);

    $triage = Mockery::mock(TriageAiService::class);
    $triage->shouldReceive('triageAll')->once()->andReturn([
        'classification' => [
            'type' => 'spam',
            'confidence' => 0.9,
            'is_abuse_report' => true,
            'summary' => 'Spam report about IP sending unsolicited emails',
        ],
        'noise' => ['noise_score' => 0.1],
        'iocs' => ['target_ips' => ['10.0.0.1'], 'reporter_ips' => [], 'domains' => [], 'urls' => []],
    ]);
    app()->instance(TriageAiService::class, $triage);

    $listener = app(HandleReportReceived::class);
    $listener->handle(new ReportReceived($report));

    $report->refresh();
    expect($report->case_id)->not->toBeNull();
    expect($report->metadata['pre_screened'])->toBeTrue();
});

it('proceeds without AI when classification fails', function () {
    $reporter = Reporter::factory()->create();
    $ip = IpAddress::factory()->create(['ip_address' => '10.0.0.2', 'status' => 'active']);
    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'target_ip' => '10.0.0.2',
        'abuse_type' => 'phishing',
        'evidence' => 'Phishing page at your IP',
    ]);

    $triage = Mockery::mock(TriageAiService::class);
    $triage->shouldReceive('triageAll')->once()->andThrow(new \Exception('API timeout'));
    app()->instance(TriageAiService::class, $triage);

    $listener = app(HandleReportReceived::class);
    $listener->handle(new ReportReceived($report));

    $report->refresh();
    // Should still create case even though AI failed
    expect($report->case_id)->not->toBeNull();
});

it('rejects reports with no target IP or domain in CaseCreatorService', function () {
    $reporter = Reporter::factory()->create();
    $report = AbuseReport::factory()->create([
        'reporter_id' => $reporter->id,
        'target_ip' => null,
        'target_domain' => null,
        'abuse_type' => 'other',
    ]);

    $caseCreator = app(CaseCreatorService::class);
    $case = $caseCreator->findOrCreateCase($report);

    expect($case)->toBeNull();
    $report->refresh();
    expect($report->metadata['skipped_reason'])->toBe('no_target_identifier');
});
