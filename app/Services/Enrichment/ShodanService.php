<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shodan API integration.
 * Checks IPs for open ports, services, vulnerabilities.
 */
class ShodanService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.shodan.io';

    public function __construct()
    {
        $this->apiKey = config('abusedesk.threat_intel.shodan_key') ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Get host information for an IP.
     */
    public function host(string $ip): ?array
    {
        return $this->get("/shodan/host/{$ip}");
    }

    /**
     * Get a summary of an IP's exposure.
     * Returns ports, vulns, OS, organization, etc.
     */
    public function getHostSummary(string $ip): ?array
    {
        $data = $this->host($ip);

        if (! $data) {
            return null;
        }

        return [
            'ip' => $data['ip_str'] ?? $ip,
            'os' => $data['os'] ?? null,
            'organization' => $data['org'] ?? null,
            'isp' => $data['isp'] ?? null,
            'asn' => $data['asn'] ?? null,
            'country' => $data['country_name'] ?? null,
            'city' => $data['city'] ?? null,
            'ports' => $data['ports'] ?? [],
            'vulns' => $data['vulns'] ?? [],
            'hostnames' => $data['hostnames'] ?? [],
            'domains' => $data['domains'] ?? [],
            'last_update' => $data['last_update'] ?? null,
            'tags' => $data['tags'] ?? [],
        ];
    }

    protected function get(string $endpoint): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(30)->get("{$this->baseUrl}{$endpoint}", [
                'key' => $this->apiKey,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 404) {
                return null;
            }

            Log::warning('Shodan API error', ['endpoint' => $endpoint, 'status' => $response->status()]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Shodan error: ' . $e->getMessage());
            return null;
        }
    }
}
