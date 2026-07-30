<?php

namespace App\Services\Infrastructure;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxmox VE REST client. Auths via API token (recommended) — username
 * + password tickets are stateful and not worth the complexity for our
 * read-mostly use case.
 *
 * The Proxmox quirk: there's no "find VM by IP" endpoint. We have to
 * walk /cluster/resources and pull each VM's config. We grep `net*` and
 * `ipconfig*` lines plus the description field for the IP — covers
 * Cloud-Init setups, KVM with explicit IP, and operators who paste IPs
 * into VM notes. Everything else needs a custom GenericHttpProvider.
 *
 * @see https://pve.proxmox.com/pve-docs/api-viewer/
 */
class ProxmoxService
{
    protected string $apiUrl;
    protected string $tokenId;       // user@realm!tokenname
    protected string $tokenSecret;

    /** Cap on number of VMs we'll scan in a single resolveIp() call. */
    protected const MAX_SCAN = 2000;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];
        $this->apiUrl = rtrim($config['url'] ?? '', '/');
        $this->tokenId = $config['token_id'] ?? '';
        $this->tokenSecret = $config['token_secret'] ?? '';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->tokenId) && ! empty($this->tokenSecret);
    }

    public function resolveIp(string $ip): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $resources = $this->get('/api2/json/cluster/resources', ['type' => 'vm']);
        $vms = $resources['data'] ?? [];
        if (empty($vms)) {
            return null;
        }

        $scanned = 0;
        foreach ($vms as $vm) {
            if ($scanned++ >= self::MAX_SCAN) {
                Log::warning('Proxmox: scan cap reached without IP match', [
                    'ip' => $ip, 'scanned' => $scanned,
                ]);
                break;
            }
            if (($vm['type'] ?? '') !== 'qemu' && ($vm['type'] ?? '') !== 'lxc') {
                continue;
            }

            $node = $vm['node'] ?? null;
            $vmid = $vm['vmid'] ?? null;
            if (! $node || ! $vmid) {
                continue;
            }

            $kind = $vm['type'] === 'lxc' ? 'lxc' : 'qemu';
            $config = $this->get("/api2/json/nodes/{$node}/{$kind}/{$vmid}/config");
            $cfg = $config['data'] ?? [];

            if (! $this->configMatchesIp($cfg, $ip)) {
                continue;
            }

            return [
                'vmid' => (string) $vmid,
                'node' => $node,
                'type' => $kind,
                'hostname' => $cfg['name'] ?? ($vm['name'] ?? null),
                'description' => $cfg['description'] ?? null,
                'ip' => $ip,
                'status' => strtolower((string) ($vm['status'] ?? 'unknown')),
                'cpu' => $vm['maxcpu'] ?? null,
                'mem_bytes' => $vm['maxmem'] ?? null,
                'disk_bytes' => $vm['maxdisk'] ?? null,
                'tags' => $cfg['tags'] ?? null,
                'raw' => $cfg + ['_resource' => $vm],
            ];
        }

        return null;
    }

    public function stop(string $node, string $kind, string $vmid): array
    {
        $resp = $this->post("/api2/json/nodes/{$node}/{$kind}/{$vmid}/status/stop");
        return $this->shapeActionResult($resp, 'stopped');
    }

    public function start(string $node, string $kind, string $vmid): array
    {
        $resp = $this->post("/api2/json/nodes/{$node}/{$kind}/{$vmid}/status/start");
        return $this->shapeActionResult($resp, 'started');
    }

    /**
     * True when an IP appears anywhere in the VM config Proxmox might
     * carry it — net* MAC+IP strings, ipconfig* (Cloud-Init), or the
     * free-text description / notes field. Bare-string match keeps
     * the parser tolerant of formatting differences across versions.
     */
    protected function configMatchesIp(array $cfg, string $ip): bool
    {
        $ipQuoted = preg_quote($ip, '/');
        $needle = '/(^|[^0-9.])' . $ipQuoted . '([^0-9.]|$)/';

        foreach ($cfg as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (preg_match('/^(net\d+|ipconfig\d+|description|notes)$/i', $key) === 1) {
                if (preg_match($needle, $value) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function shapeActionResult(?array $resp, string $verb): array
    {
        if (! $resp) {
            return ['success' => false, 'message' => "Proxmox unreachable for {$verb}"];
        }
        // Proxmox returns a UPID (task id) for async actions; treat any
        // 2xx as success since the action has been accepted by the cluster.
        $ok = isset($resp['data']);
        return [
            'success' => $ok,
            'message' => $ok ? "VM {$verb}" : ($resp['errors'] ?? "{$verb} failed"),
            'raw' => $resp,
        ];
    }

    protected function get(string $path, array $query = []): ?array
    {
        return $this->request('get', $path, $query);
    }

    protected function post(string $path, array $body = []): ?array
    {
        return $this->request('post', $path, $body);
    }

    protected function request(string $method, string $path, array $payload = []): ?array
    {
        try {
            $client = $this->client();
            $resp = $method === 'post'
                ? $client->asForm()->post($this->apiUrl . $path, $payload)
                : $client->get($this->apiUrl . $path, $payload);

            if (! $resp->successful()) {
                Log::warning('Proxmox API non-2xx', [
                    'path' => $path, 'http' => $resp->status(),
                ]);
                return null;
            }
            $json = $resp->json();
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::error('Proxmox API error', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "PVEAPIToken={$this->tokenId}={$this->tokenSecret}",
        ])->withoutVerifying()->timeout(30);
    }
}
