<?php

use App\Services\AI\AiProviderInterface;
use App\Services\AI\ClaudeService;
use App\Services\AI\UsageResult;

/**
 * A provider stub that always returns one fixed body — lets us drive
 * ClaudeService's JSON handling without a real API call.
 */
function fakeAiProvider(?string $text): AiProviderInterface
{
    return new class($text) implements AiProviderInterface
    {
        public function __construct(private ?string $text) {}

        public function complete(string $s, string $u, ?int $m = null): ?string
        {
            return $this->text;
        }

        public function completeJson(string $s, string $u, ?int $m = null): ?array
        {
            return null;
        }

        public function completeWithUsage(string $s, string $u, ?int $m = null, bool $j = false): UsageResult
        {
            return new UsageResult($this->text, 'openai', 'gpt-4o', 10, 20);
        }

        public function getProviderName(): string
        {
            return 'openai';
        }

        public function getModelName(): string
        {
            return 'gpt-4o';
        }
    };
}

it('recovers classification + noise from a triage response truncated at the token cap', function () {
    // iocs is cut off mid-string — exactly how an over-cap response lands.
    $truncated = '{"classification":{"type":"malware","confidence":0.9,"is_abuse_report":true,"summary":"infected host"},'
        . '"noise":{"noise_score":0.05,"is_not_abuse":false,"reason":"clear evidence"},'
        . '"iocs":{"target_ips":["1.2.3.4","5.6.7';

    $service = new ClaudeService(fakeAiProvider($truncated));
    $parsed = $service->completeJson('system', 'user');

    expect($parsed)->toBeArray();
    expect($parsed['classification']['type'])->toBe('malware');
    expect($parsed['noise']['noise_score'])->toBe(0.05);
});

it('parses a clean JSON response unchanged', function () {
    $service = new ClaudeService(fakeAiProvider('{"classification":{"type":"phishing"}}'));

    expect($service->completeJson('system', 'user')['classification']['type'])->toBe('phishing');
});

it('scrubs invalid UTF-8 before the text reaches the provider', function () {
    // A provider stub that records what it was handed.
    $provider = new class implements AiProviderInterface
    {
        public ?string $seenUser = null;

        public function complete(string $s, string $u, ?int $m = null): ?string
        {
            return null;
        }

        public function completeJson(string $s, string $u, ?int $m = null): ?array
        {
            return null;
        }

        public function completeWithUsage(string $s, string $u, ?int $m = null, bool $j = false): UsageResult
        {
            $this->seenUser = $u;

            return new UsageResult('{"classification":{"type":"spam"}}', 'openai', 'gpt-4o');
        }

        public function getProviderName(): string
        {
            return 'openai';
        }

        public function getModelName(): string
        {
            return 'gpt-4o';
        }
    };

    (new ClaudeService($provider))->completeJson('system', "report body \x80\xFF invalid");

    expect($provider->seenUser)->not->toBeNull();
    expect(mb_check_encoding($provider->seenUser, 'UTF-8'))->toBeTrue();
    // Without the scrub this throws "Malformed UTF-8 characters".
    expect(json_encode(['content' => $provider->seenUser]))->toBeString();
});

it('returns null for an unrepairable / empty response', function () {
    expect((new ClaudeService(fakeAiProvider('')))->completeJson('s', 'u'))->toBeNull();
    expect((new ClaudeService(fakeAiProvider('not json at all')))->completeJson('s', 'u'))->toBeNull();
});
