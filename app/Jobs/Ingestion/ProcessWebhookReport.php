<?php

namespace App\Jobs\Ingestion;

use App\Events\ReportReceived;
use App\Models\Reporter;
use App\Services\Ingestion\Feeds\FblProcessor;
use App\Services\Ingestion\Feeds\SpamCopParser;
use App\Services\Ingestion\Parsers\ArfParser;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWebhookReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public string $provider,
        public array $payload,
        public string $signature,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(ReportNormalizerService $normalizer): void
    {
        $reporter = Reporter::firstOrCreate(
            ['email' => "{$this->provider}@feeds.abuseai"],
            [
                'name' => $this->providerName(),
                'type' => 'feed',
                'is_trusted' => true,
            ],
        );

        $parsed = $this->parseByProvider();

        $report = $normalizer->fromFeed([
            'abuse_type' => $parsed['abuse_type'] ?? 'other',
            'target_ip' => $parsed['target_ip'] ?? $parsed['source_ip'] ?? null,
            'target_domain' => $parsed['reported_domain'] ?? null,
            'target_url' => $parsed['extracted_urls'][0] ?? null,
            'evidence' => $parsed['evidence'] ?? json_encode($this->payload),
            'headers' => $parsed['headers'] ?? null,
            'reported_at' => $parsed['arrival_date'] ?? $parsed['complaint_date'] ?? now(),
        ], $reporter);

        // Store extracted IOCs in enrichment
        if (! empty($parsed['extracted_ips']) || ! empty($parsed['extracted_domains']) || ! empty($parsed['extracted_urls'])) {
            $report->update([
                'enrichment' => array_merge($report->enrichment ?? [], [
                    'iocs' => [
                        'ips' => $parsed['extracted_ips'] ?? [],
                        'domains' => $parsed['extracted_domains'] ?? [],
                        'urls' => $parsed['extracted_urls'] ?? [],
                    ],
                    'source_provider' => $this->provider,
                ]),
            ]);
        }

        ReportReceived::dispatch($report);

        Log::info("Webhook report processed from {$this->provider}", [
            'report_id' => $report->id,
            'abuse_type' => $parsed['abuse_type'] ?? 'other',
            'target_ip' => $parsed['target_ip'] ?? $parsed['source_ip'] ?? null,
        ]);
    }

    protected function parseByProvider(): array
    {
        $rawBody = $this->payload['_raw_body'] ?? null;

        return match ($this->provider) {
            'google_fbl' => app(FblProcessor::class)->processGoogleFbl($this->payload),
            'microsoft_fbl' => app(FblProcessor::class)->processMicrosoftFbl($this->payload),
            'spamcop' => $rawBody
                ? app(SpamCopParser::class)->parse($rawBody)
                : ['abuse_type' => 'spam', 'evidence' => json_encode($this->payload)],
            'generic_arf', 'email_inbound' => $rawBody
                ? $this->parseArf($rawBody)
                : ['abuse_type' => 'other', 'evidence' => json_encode($this->payload)],
            default => $this->parseGeneric(),
        };
    }

    protected function parseArf(string $rawBody): array
    {
        $parsed = app(ArfParser::class)->parse($rawBody);

        if (! $parsed) {
            return ['abuse_type' => 'other', 'evidence' => $rawBody];
        }

        return [
            'abuse_type' => $parsed['abuse_type'],
            'target_ip' => $parsed['source_ip'],
            'source_ip' => $parsed['source_ip'],
            'reported_domain' => $parsed['reported_domain'],
            'evidence' => $parsed['human_readable'] ?? $rawBody,
            'headers' => $parsed['headers'],
            'extracted_ips' => $parsed['extracted_ips'],
            'extracted_domains' => $parsed['extracted_domains'],
            'extracted_urls' => $parsed['extracted_urls'],
        ];
    }

    protected function parseGeneric(): array
    {
        $ips = app(ArfParser::class)->extractIps(json_encode($this->payload));

        return [
            'abuse_type' => $this->payload['abuse_type'] ?? $this->payload['type'] ?? 'other',
            'target_ip' => $this->payload['target_ip'] ?? $this->payload['ip'] ?? ($ips[0] ?? null),
            'reported_domain' => $this->payload['domain'] ?? $this->payload['target_domain'] ?? null,
            'evidence' => json_encode($this->payload, JSON_PRETTY_PRINT),
            'extracted_ips' => $ips,
        ];
    }

    protected function providerName(): string
    {
        return match ($this->provider) {
            'abuseipdb' => 'AbuseIPDB',
            'spamhaus' => 'Spamhaus',
            'google_fbl' => 'Google FBL',
            'microsoft_fbl' => 'Microsoft FBL',
            'spamcop' => 'SpamCop',
            'generic_arf' => 'ARF Report',
            'email_inbound' => 'Email Inbound',
            default => ucfirst($this->provider),
        };
    }
}
