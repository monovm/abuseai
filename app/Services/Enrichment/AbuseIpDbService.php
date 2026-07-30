<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AbuseIpDbService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.abuseipdb.com/api/v2';

    public function __construct()
    {
        $this->apiKey = config('abusedesk.feeds.abuseipdb.api_key') ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Check a single IP against AbuseIPDB.
     * Returns: abuseConfidenceScore, totalReports, countryCode, usageType, isp, domain, lastReportedAt, reports[]
     */
    public function check(string $ip, int $maxAgeDays = 90, bool $verbose = false): ?array
    {
        try {
            $params = [
                'ipAddress' => $ip,
                'maxAgeInDays' => $maxAgeDays,
            ];

            if ($verbose) {
                $params['verbose'] = '';
            }

            $response = Http::withHeaders([
                'Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$this->baseUrl}/check", $params);

            if ($response->successful()) {
                return $response->json('data');
            }

            Log::warning('AbuseIPDB check failed', [
                'ip' => $ip,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('AbuseIPDB check error: ' . $e->getMessage(), ['ip' => $ip]);
            return null;
        }
    }

    /**
     * Get reports for a specific IP.
     */
    public function reports(string $ip, int $maxAgeDays = 90, int $perPage = 25, int $page = 1): ?array
    {
        try {
            $response = Http::withHeaders([
                'Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$this->baseUrl}/reports", [
                'ipAddress' => $ip,
                'maxAgeInDays' => $maxAgeDays,
                'perPage' => $perPage,
                'page' => $page,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('AbuseIPDB reports error: ' . $e->getMessage(), ['ip' => $ip]);
            return null;
        }
    }

    /**
     * Bulk check multiple IPs (up to 10,000 per request via CSV).
     * Note: requires AbuseIPDB subscription plan.
     */
    public function checkBlock(string $cidr, int $maxAgeDays = 30): ?array
    {
        try {
            $response = Http::withHeaders([
                'Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(60)->get("{$this->baseUrl}/check-block", [
                'network' => $cidr,
                'maxAgeInDays' => $maxAgeDays,
            ]);

            if ($response->successful()) {
                return $response->json('data');
            }

            Log::warning('AbuseIPDB check-block failed', [
                'cidr' => $cidr,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('AbuseIPDB check-block error: ' . $e->getMessage(), ['cidr' => $cidr]);
            return null;
        }
    }

    /**
     * Get the blacklist (most reported IPs).
     */
    public function blacklist(int $confidenceMinimum = 90, int $limit = 100): ?array
    {
        try {
            $response = Http::withHeaders([
                'Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$this->baseUrl}/blacklist", [
                'confidenceMinimum' => $confidenceMinimum,
                'limit' => $limit,
            ]);

            if ($response->successful()) {
                return $response->json('data');
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('AbuseIPDB blacklist error: ' . $e->getMessage());
            return null;
        }
    }
}
