<?php

use App\Services\AI\ClaudeService;
use App\Services\AI\TriageAiService;

it('does not cache a malformed triage result so a retry re-calls the provider', function () {
    $claude = Mockery::mock(ClaudeService::class);
    // First call returns an unusable shape (no classification block),
    // the second a well-formed result — as a transient retry would.
    $claude->shouldReceive('completeJson')->twice()->andReturn(
        ['noise' => ['noise_score' => 0.1]],
        ['classification' => ['type' => 'spam', 'confidence' => 0.9]],
    );

    $triage = new TriageAiService($claude);

    $first = $triage->triageAll('Spam from 1.2.3.4');
    expect($first)->toBe(['noise' => ['noise_score' => 0.1]]);

    // Same content: the malformed result must NOT have been cached, so
    // the provider is hit again and the retry recovers.
    $second = $triage->triageAll('Spam from 1.2.3.4');
    expect($second['classification']['type'])->toBe('spam');
});

it('caches a well-formed triage result so a retry costs no API call', function () {
    $claude = Mockery::mock(ClaudeService::class);
    $claude->shouldReceive('completeJson')->once()->andReturn(
        ['classification' => ['type' => 'phishing', 'confidence' => 0.95]],
    );

    $triage = new TriageAiService($claude);

    $first = $triage->triageAll('Phishing at evil.example');
    $second = $triage->triageAll('Phishing at evil.example');

    expect($second)->toBe($first);
    expect($second['classification']['type'])->toBe('phishing');
});

it('normalizes a flat classifier-shape response into the combined shape', function () {
    $claude = Mockery::mock(ClaudeService::class);
    // Model returned the bare classifier shape, not {classification:{…}}.
    $claude->shouldReceive('completeJson')->once()->andReturn([
        'type' => 'fraud',
        'confidence' => 0.8,
        'is_abuse_report' => false,
        'summary' => 'advance-fee donation scam',
        'iocs' => ['target_ips' => []],
    ]);

    $triage = new TriageAiService($claude);
    $result = $triage->triageAll('You have won 1,000,000 EUR');

    expect($result['classification']['type'])->toBe('fraud');
    expect($result['classification']['is_abuse_report'])->toBeFalse();
    expect($result['iocs'])->toBe(['target_ips' => []]);
});

it('unwraps a triage result nested under a single wrapper key', function () {
    $claude = Mockery::mock(ClaudeService::class);
    $claude->shouldReceive('completeJson')->once()->andReturn([
        'result' => ['classification' => ['type' => 'spam', 'confidence' => 0.9]],
    ]);

    $triage = new TriageAiService($claude);

    expect($triage->triageAll('spam content')['classification']['type'])->toBe('spam');
});

it('re-calls the provider when forceFresh is set, bypassing the cache', function () {
    $claude = Mockery::mock(ClaudeService::class);
    $claude->shouldReceive('completeJson')->twice()->andReturn(
        ['classification' => ['type' => 'spam']],
        ['classification' => ['type' => 'phishing']],
    );

    $triage = new TriageAiService($claude);

    // First call caches a usable result.
    expect($triage->triageAll('report content X')['classification']['type'])->toBe('spam');

    // forceFresh must ignore that cache entry and hit the provider again.
    $forced = $triage->triageAll('report content X', [], forceFresh: true);
    expect($forced['classification']['type'])->toBe('phishing');
});

it('returns null and skips the provider for empty content', function () {
    $claude = Mockery::mock(ClaudeService::class);
    $claude->shouldNotReceive('completeJson');

    $triage = new TriageAiService($claude);

    expect($triage->triageAll('   '))->toBeNull();
});
