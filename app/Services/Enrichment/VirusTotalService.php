<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VirusTotal API v3 integration.
 * Checks IPs, domains, and URLs for malicious activity.
 */
class VirusTotalService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://www.virustotal.com/api/v3';

    public function __construct()
    {
        $this->apiKey = config('abusedesk.threat_intel.virustotal_key') ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Check an IP address.
     */
    public function checkIp(string $ip): ?array
    {
        return $this->get("/ip_addresses/{$ip}");
    }

    /**
     * Check a domain.
     */
    public function checkDomain(string $domain): ?array
    {
        return $this->get("/domains/{$domain}");
    }

    /**
     * Check a URL (submit for scanning if not cached).
     */
    public function checkUrl(string $url): ?array
    {
        $urlId = base64_encode($url);
        $urlId = rtrim($urlId, '=');

        return $this->get("/urls/{$urlId}");
    }

    /**
     * Get a summary of malicious detections for an IP.
     * Returns ['malicious' => int, 'suspicious' => int, 'clean' => int, 'reputation' => int]
     */
    public function getIpReputation(string $ip): ?array
    {
        $data = $this->checkIp($ip);

        if (! $data) {
            return null;
        }

        $attrs = $data['data']['attributes'] ?? [];
        $stats = $attrs['last_analysis_stats'] ?? [];

        return [
            'malicious' => $stats['malicious'] ?? 0,
            'suspicious' => $stats['suspicious'] ?? 0,
            'undetected' => $stats['undetected'] ?? 0,
            'harmless' => $stats['harmless'] ?? 0,
            'reputation' => $attrs['reputation'] ?? 0,
            'as_owner' => $attrs['as_owner'] ?? null,
            'country' => $attrs['country'] ?? null,
            'network' => $attrs['network'] ?? null,
            'last_analysis_date' => isset($attrs['last_analysis_date'])
                ? date('Y-m-d H:i:s', $attrs['last_analysis_date'])
                : null,
        ];
    }

    /**
     * Get domain reputation.
     */
    public function getDomainReputation(string $domain): ?array
    {
        $data = $this->checkDomain($domain);

        if (! $data) {
            return null;
        }

        $attrs = $data['data']['attributes'] ?? [];
        $stats = $attrs['last_analysis_stats'] ?? [];

        return [
            'malicious' => $stats['malicious'] ?? 0,
            'suspicious' => $stats['suspicious'] ?? 0,
            'undetected' => $stats['undetected'] ?? 0,
            'harmless' => $stats['harmless'] ?? 0,
            'reputation' => $attrs['reputation'] ?? 0,
            'registrar' => $attrs['registrar'] ?? null,
            'creation_date' => isset($attrs['creation_date'])
                ? date('Y-m-d H:i:s', $attrs['creation_date'])
                : null,
            'categories' => $attrs['categories'] ?? [],
        ];
    }

    protected function get(string $endpoint): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-apikey' => $this->apiKey,
            ])->timeout(30)->get("{$this->baseUrl}{$endpoint}");

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 404) {
                return null; // Not found is not an error
            }

            Log::warning('VirusTotal API error', ['endpoint' => $endpoint, 'status' => $response->status()]);
            return null;
        } catch (\Throwable $e) {
            Log::error('VirusTotal error: ' . $e->getMessage());
            return null;
        }
    }
}
