<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft SNDS (Smart Network Data Services) integration.
 *
 * SNDS provides IP reputation data from Microsoft's perspective:
 * - Spam trap hits
 * - Filter results (green/yellow/red)
 * - Complaint rate
 * - Sample messages
 *
 * Register at: https://sendersupport.olc.protection.outlook.com/snds/
 * You need to verify ownership of your IP ranges.
 *
 * SNDS provides data via automated exports (CSV) or the SNDS API.
 */
class SndsService
{
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('abusedesk.collectors.snds_key') ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Get SNDS data for all our IPs (automated data access).
     * Returns array of IP status records.
     *
     * SNDS automated data URL format:
     * https://sendersupport.olc.protection.outlook.com/snds/data.aspx?key=YOUR_KEY
     *
     * Returns CSV: start_ip, end_ip, blocked, trap_hits, filter_result, sample_count
     */
    public function fetchAllData(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $response = Http::timeout(60)->get(
                'https://sendersupport.olc.protection.outlook.com/snds/data.aspx',
                ['key' => $this->apiKey]
            );

            if (! $response->successful()) {
                Log::warning('SNDS fetch failed', ['status' => $response->status()]);
                return [];
            }

            return $this->parseCsvResponse($response->body());
        } catch (\Throwable $e) {
            Log::error('SNDS error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get SNDS IP status data (per IP range).
     * Returns array of ['start_ip', 'end_ip', 'blocked', 'trap_hits', 'filter_result', 'sample_count']
     */
    public function fetchIpStatus(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $response = Http::timeout(60)->get(
                'https://sendersupport.olc.protection.outlook.com/snds/ipStatus.aspx',
                ['key' => $this->apiKey]
            );

            if (! $response->successful()) {
                Log::warning('SNDS IP status fetch failed', ['status' => $response->status()]);
                return [];
            }

            return $this->parseCsvResponse($response->body());
        } catch (\Throwable $e) {
            Log::error('SNDS IP status error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a specific IP has SNDS issues.
     * Searches the SNDS data for the IP's range.
     */
    public function checkIp(string $ip, ?array $cachedData = null): ?array
    {
        // Microsoft SNDS only publishes IPv4 ranges. Skip IPv6 cleanly rather
        // than forcing ip2long to mangle it.
        if (! \App\Support\IpHelpers::isIpv4($ip)) {
            return null;
        }

        $data = $cachedData ?? $this->fetchAllData();
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return null;
        }

        foreach ($data as $row) {
            $startLong = ip2long($row['start_ip'] ?? '');
            $endLong = ip2long($row['end_ip'] ?? '');

            if ($startLong !== false && $endLong !== false &&
                $ipLong >= $startLong && $ipLong <= $endLong) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Parse SNDS CSV response into structured array.
     */
    protected function parseCsvResponse(string $csv): array
    {
        $lines = explode("\n", trim($csv));
        $results = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $fields = str_getcsv($line);

            if (count($fields) < 4) {
                continue;
            }

            $results[] = [
                'start_ip' => trim($fields[0] ?? ''),
                'end_ip' => trim($fields[1] ?? ''),
                'blocked' => trim($fields[2] ?? '') === 'Yes',
                'trap_hits' => (int) ($fields[3] ?? 0),
                'filter_result' => strtolower(trim($fields[4] ?? 'unknown')), // green, yellow, red
                'sample_count' => (int) ($fields[5] ?? 0),
            ];
        }

        return $results;
    }

    /**
     * Interpret SNDS filter result.
     */
    public static function interpretFilterResult(string $result): string
    {
        return match (strtolower($result)) {
            'green' => 'Good — low spam, no issues',
            'yellow' => 'Warning — elevated spam signals',
            'red' => 'Bad — high spam, likely blocked at Outlook/Hotmail',
            default => "Unknown ({$result})",
        };
    }
}
