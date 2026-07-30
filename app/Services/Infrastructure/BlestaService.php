<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Blesta REST client. Auth is HTTP Basic with API user + key. Endpoints
 * follow the pattern `/api/{model}/{method}.{format}` — we always ask
 * for JSON.
 *
 * Two lookup paths analogous to WhmcsService:
 *   - findServiceByDomain()  — exact domain match (one call)
 *   - findServiceByIp()      — paginated scan filtering on overrideips
 *
 * "domain" in Blesta is roughly equivalent to WHMCS's domain field —
 * many hosters store the VPS hostname there.
 *
 * @see https://docs.blesta.com/display/dev/API
 */
class BlestaService
{
    protected string $apiUrl;
    protected string $apiUser;
    protected string $apiKey;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];
        $this->apiUrl = rtrim($config['url'] ?? '', '/');
        $this->apiUser = $config['api_user'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->apiUser) && ! empty($this->apiKey);
    }

    /** Find a service by its `domain` field (often the VPS hostname). */
    public function findServiceByDomain(string $domain): ?array
    {
        $resp = $this->call('services/getall', ['filters' => ['domain' => $domain]]);
        $service = $this->firstService($resp);
        return $service ? $this->shapeService($service) : null;
    }

    /**
     * Walk all services looking for an IP match. Slow on large installs;
     * cap with $maxScan. Blesta has no native IP filter — the IP usually
     * lives in module-specific custom fields (overrideips, dedicatedip).
     */
    public function findServiceByIp(string $ip, int $maxScan = 5000): ?array
    {
        // Cheap shortcut: some operators store the IP in `domain`.
        if ($shortcut = $this->findServiceByDomain($ip)) {
            return $shortcut;
        }

        $page = 1;
        $perPage = 100;
        $scanned = 0;

        while ($scanned < $maxScan) {
            $resp = $this->call('services/getall', [
                'page' => $page,
                'per_page' => $perPage,
            ]);
            $services = $this->servicesFromResponse($resp);
            if (empty($services)) {
                return null;
            }

            foreach ($services as $service) {
                if ($this->serviceHasIp($service, $ip)) {
                    return $this->shapeService($service);
                }
            }

            $scanned += count($services);
            if (count($services) < $perPage) {
                return null;
            }
            $page++;
        }

        return null;
    }

    public function suspendService(string $serviceId, string $reason): array
    {
        $resp = $this->call('services/edit', [
            'service_id' => $serviceId,
            'vars' => ['status' => 'suspended', 'suspension_reason' => $reason],
        ]);
        return $this->shapeActionResult($resp, 'suspended');
    }

    public function unsuspendService(string $serviceId): array
    {
        $resp = $this->call('services/edit', [
            'service_id' => $serviceId,
            'vars' => ['status' => 'active'],
        ]);
        return $this->shapeActionResult($resp, 'unsuspended');
    }

    /**
     * Best-effort: check Blesta's known IP-bearing fields. Different
     * server modules use different field names so we try the common
     * ones rather than asking operators to map per brand.
     */
    protected function serviceHasIp(array $service, string $ip): bool
    {
        $candidateFields = ['dedicated_ip', 'mainip', 'ip', 'overrideips'];
        foreach ($candidateFields as $field) {
            $val = $service['fields'][$field] ?? ($service[$field] ?? null);
            if (is_string($val) && trim($val) === $ip) {
                return true;
            }
            if (is_array($val) && in_array($ip, $val, true)) {
                return true;
            }
        }
        // Some modules nest IPs inside an "options" array of {key,value}.
        foreach (($service['options'] ?? []) as $opt) {
            if (($opt['key'] ?? null) === 'ip' && trim((string) ($opt['value'] ?? '')) === $ip) {
                return true;
            }
        }
        return false;
    }

    protected function servicesFromResponse(?array $resp): array
    {
        // Blesta wraps responses as {response: ..., response_code: ...}.
        $body = $resp['response'] ?? null;
        if (is_array($body)) {
            return $body;
        }
        return [];
    }

    protected function firstService(?array $resp): ?array
    {
        $services = $this->servicesFromResponse($resp);
        return $services[0] ?? null;
    }

    protected function shapeService(array $s): array
    {
        return [
            'id' => $s['id'] ?? null,
            'client_id' => $s['client_id'] ?? null,
            'package_name' => $s['package']['name'] ?? null,
            'name' => $s['package']['name'] ?? ($s['package_name'] ?? null),
            'status' => $s['status'] ?? null,
            'domain' => $s['domain'] ?? null,
            'date_added' => $s['date_added'] ?? null,
            'date_renews' => $s['date_renews'] ?? null,
            'currency' => $s['currency'] ?? null,
            'price' => $s['price'] ?? null,
            'override_price' => $s['override_price'] ?? null,
            'fields' => $s['fields'] ?? [],
            'raw' => $s,
        ];
    }

    protected function shapeActionResult(?array $resp, string $verb): array
    {
        if (! $resp) {
            return ['success' => false, 'message' => "Blesta unreachable for {$verb}"];
        }
        $ok = ($resp['response_code'] ?? null) === '200';
        return [
            'success' => $ok,
            'message' => $ok ? "Service {$verb}" : ($resp['response']['message'] ?? "{$verb} failed"),
            'raw' => $resp,
        ];
    }

    protected function call(string $path, array $params = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $url = "{$this->apiUrl}/{$path}.json";
            $resp = Http::withBasicAuth($this->apiUser, $this->apiKey)
                ->withoutVerifying()
                ->timeout(30)
                ->asForm()
                ->post($url, $params);

            $json = $resp->json();
            Log::info('Blesta API', [
                'path' => $path, 'http' => $resp->status(),
                'code' => is_array($json) ? ($json['response_code'] ?? null) : null,
            ]);
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::error('Blesta API error', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
