<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SolusVM v1 Admin API client. Targets the legacy `command.php` endpoint
 * since SolusVM 1 is what most existing hosting brands still run; v2 has
 * a separate REST API and would be a sibling class.
 *
 * Auth: id + key, posted as form fields. Responses are XML by default,
 * but the API accepts `rdtype=json` so we always request JSON to keep
 * parsing trivial.
 *
 * @see https://documentation.solusvm.com/display/DOCS/Admin+API
 */
class SolusVmService
{
    protected string $apiUrl;
    protected string $apiId;
    protected string $apiKey;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];
        $this->apiUrl = rtrim($config['url'] ?? '', '/');
        $this->apiId = $config['api_id'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->apiId) && ! empty($this->apiKey);
    }

    /**
     * Resolve an IP to its VPS, returning the flat shape used by
     * SolusVmProvider::lookupByIp.
     *
     * Two API calls in sequence:
     *   1. vserver-checkexists?ipaddress={ip} → vserverid
     *   2. vserver-info?vserverid={id}        → full record
     */
    public function resolveIp(string $ip): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $exists = $this->call('vserver-checkexists', ['ipaddress' => $ip]);
        $vserverId = $exists['vserverid'] ?? null;
        if (! $vserverId) {
            return null;
        }

        $info = $this->call('vserver-info', ['vserverid' => $vserverId]);
        if (! $info || ($info['status'] ?? '') !== 'success') {
            return null;
        }

        return [
            'vserverid' => (string) $vserverId,
            'hostname' => $info['hostname'] ?? null,
            'ipaddress' => $info['ipaddress'] ?? $ip,
            'mainipaddress' => $info['mainipaddress'] ?? $ip,
            'status' => strtolower((string) ($info['status'] ?? 'unknown')),
            'state' => strtolower((string) ($info['state'] ?? 'unknown')),     // online|offline|disabled
            'suspended' => ($info['suspended'] ?? '0') !== '0',
            'clientid' => $info['clientid'] ?? null,
            'clientname' => $info['clientname'] ?? null,
            'clientemail' => $info['clientemail'] ?? null,
            'plan' => $info['plan'] ?? null,
            'node' => $info['node'] ?? null,
            'type' => $info['type'] ?? null,
            'hdd' => $info['hdd'] ?? null,
            'mem' => $info['mem'] ?? null,
            'bw' => $info['bw'] ?? null,
            'created' => $info['created'] ?? null,
            'raw' => $info,
        ];
    }

    public function suspend(string $vserverId, string $reason): array
    {
        $resp = $this->call('vserver-suspend', [
            'vserverid' => $vserverId,
            'reason' => $reason,
        ]);
        return $this->shapeActionResult($resp, 'suspended');
    }

    public function unsuspend(string $vserverId): array
    {
        $resp = $this->call('vserver-unsuspend', ['vserverid' => $vserverId]);
        return $this->shapeActionResult($resp, 'unsuspended');
    }

    protected function shapeActionResult(?array $resp, string $verb): array
    {
        if (! $resp) {
            return ['success' => false, 'message' => "SolusVM unreachable for {$verb}"];
        }
        $ok = ($resp['status'] ?? '') === 'success';
        return [
            'success' => $ok,
            'message' => $resp['statusmsg'] ?? ($ok ? "VPS {$verb}" : "{$verb} failed"),
            'raw' => $resp,
        ];
    }

    /**
     * POST a SolusVM v1 admin command. The API tolerates GET as well, but
     * POST keeps secrets out of access logs even when TLS terminates at a
     * proxy that mirrors URLs.
     */
    protected function call(string $action, array $params = []): ?array
    {
        try {
            $response = Http::asForm()
                ->withoutVerifying()  // hosters rarely have valid certs on panel ports
                ->timeout(30)
                ->post($this->apiUrl, array_merge($params, [
                    'action' => $action,
                    'id' => $this->apiId,
                    'key' => $this->apiKey,
                    'rdtype' => 'json',
                ]));

            $json = $response->json();
            Log::info('SolusVM API', [
                'action' => $action,
                'http' => $response->status(),
                'status' => is_array($json) ? ($json['status'] ?? null) : null,
            ]);

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::error('SolusVM API error', ['action' => $action, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
