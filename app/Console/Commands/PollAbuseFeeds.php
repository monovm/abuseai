<?php

namespace App\Console\Commands;

use App\Events\ReportReceived;
use App\Models\Reporter;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PollAbuseFeeds extends Command
{
    protected $signature = 'abuse:poll-feeds';
    protected $description = 'Poll external abuse feeds (AbuseIPDB, etc.)';

    public function handle(ReportNormalizerService $normalizer): int
    {
        $this->info('Polling abuse feeds...');

        if (config('abusedesk.feeds.abuseipdb.enabled')) {
            $this->pollAbuseIpDb($normalizer);
        }

        $this->info('Feed polling complete.');
        return self::SUCCESS;
    }

    protected function pollAbuseIpDb(ReportNormalizerService $normalizer): void
    {
        $apiKey = config('abusedesk.feeds.abuseipdb.api_key');
        if (! $apiKey) {
            $this->warn('AbuseIPDB API key not configured, skipping.');
            return;
        }

        $this->info('Polling AbuseIPDB...');

        $reporter = Reporter::firstOrCreate(
            ['email' => 'abuseipdb@feeds.abuseai'],
            ['name' => 'AbuseIPDB', 'type' => 'feed', 'is_trusted' => true],
        );

        try {
            $lastCursor = Redis::get('feeds:abuseipdb:last_cursor') ?? now()->subMinutes(15)->toIso8601String();

            $response = Http::withHeaders([
                'Key' => $apiKey,
                'Accept' => 'application/json',
            ])->get('https://api.abuseipdb.com/api/v2/reports', [
                'maxAgeInDays' => 1,
                'page' => 1,
                'perPage' => 100,
            ]);

            if (! $response->successful()) {
                $this->error("AbuseIPDB API returned {$response->status()}");
                return;
            }

            $data = $response->json('data', []);
            $count = 0;

            foreach ($data as $item) {
                $report = $normalizer->fromFeed([
                    'abuse_type' => $this->mapCategory($item['categories'] ?? []),
                    'target_ip' => $item['reportedAddress'] ?? null,
                    'evidence' => $item['comment'] ?? null,
                    'reported_at' => $item['reportedAt'] ?? now(),
                ], $reporter);

                ReportReceived::dispatch($report);
                $count++;
            }

            Redis::set('feeds:abuseipdb:last_cursor', now()->toIso8601String());
            $this->info("AbuseIPDB: {$count} reports ingested.");
        } catch (\Throwable $e) {
            Log::error('AbuseIPDB poll failed: ' . $e->getMessage());
            $this->error('AbuseIPDB poll failed: ' . $e->getMessage());
        }
    }

    protected function mapCategory(array $categories): string
    {
        // AbuseIPDB category mapping
        $map = [
            3 => 'fraud', 4 => 'ddos', 5 => 'ddos',
            9 => 'spam', 10 => 'spam', 11 => 'spam',
            14 => 'phishing', 15 => 'malware', 16 => 'malware',
            18 => 'ddos', 19 => 'phishing', 20 => 'fraud',
        ];

        foreach ($categories as $cat) {
            if (isset($map[$cat])) {
                return $map[$cat];
            }
        }

        return 'other';
    }
}
