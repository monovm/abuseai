<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Virtualizor Admin API integration.
 * Supports multiple Virtualizor instances (one per brand).
 *
 * @see https://www.virtualizor.com/docs/admin-api/list-virtual-servers/
 */
class VirtualizorService
{
    protected string $apiUrl;
    protected string $apiKey;
    protected string $apiPass;

    public function __construct(?array $config = null)
    {
        if ($config) {
            $this->apiUrl = rtrim($config['url'] ?? '', '/');
            $this->apiKey = $config['api_key'] ?? '';
            $this->apiPass = $config['api_pass'] ?? '';
        } else {
            $this->apiUrl = rtrim(config('abusedesk.virtualizor.url', ''), '/');
            $this->apiKey = config('abusedesk.virtualizor.api_key', '');
            $this->apiPass = config('abusedesk.virtualizor.api_pass', '');
        }
    }

    public static function fromBrandConfig(array $config): self
    {
        return new self($config);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->apiKey) && ! empty($this->apiPass);
    }

    /**
     * Find VPS by IP address using List Virtual Servers API.
     * Single API call: act=vs&vpsip={ip}
     */
    public function resolveIp(string $ip): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            Log::info("Virtualizor: looking up IP {$ip}");

            $response = Http::withoutVerifying()
                ->timeout(60)
                ->connectTimeout(30)
                ->get("{$this->apiUrl}/index.php", [
                    'act' => 'vs',
                    'api' => 'json',
                    'adminapikey' => $this->apiKey,
                    'adminapipass' => $this->apiPass,
                    'vpsip' => $ip,
                ]);

            Log::info("Virtualizor: HTTP {$response->status()}, body length: " . strlen($response->body()));

            if (! $response->successful()) {
                Log::warning("Virtualizor: HTTP {$response->status()} for {$ip}");
                return null;
            }

            $data = $response->json();
            $vsList = $data['vs'] ?? [];

            if (empty($vsList) || ! is_array($vsList)) {
                Log::info("Virtualizor: no VPS found for {$ip}");
                return null;
            }

            $vps = reset($vsList);

            if (! $vps || ! is_array($vps)) {
                return null;
            }

            $vpsId = $vps['vpsid'] ?? null;
            $email = $vps['email'] ?? null;
            $hostname = $vps['hostname'] ?? null;
            $suspended = ($vps['suspended'] ?? '0') !== '0';

            Log::info("Virtualizor: found VPS {$vpsId} for {$ip}", [
                'email' => $email, 'hostname' => $hostname,
            ]);

            $allIps = [];
            if (isset($vps['ips']) && is_array($vps['ips'])) {
                $allIps = array_values($vps['ips']);
            }

            return [
                'vpsid' => $vpsId,
                'email' => $email,
                'uid' => $vps['uid'] ?? null,
                'vps' => [
                    'vpsid' => $vpsId,
                    'vps_name' => $vps['vps_name'] ?? null,
                    'hostname' => $hostname,
                    'os_name' => $vps['os_name'] ?? null,
                    'os_distro' => $vps['os_distro'] ?? null,
                    'status' => $suspended ? 'Suspended' : 'Running',
                    'suspended' => $suspended,
                    'suspend_reason' => $vps['suspend_reason'] ?? null,
                    'ram' => $vps['ram'] ?? null,
                    'disk' => $vps['space'] ?? null,
                    'cpu' => $vps['cores'] ?? null,
                    'bandwidth' => $vps['bandwidth'] ?? null,
                    'used_bandwidth' => $vps['used_bandwidth'] ?? null,
                    'ip' => $ip,
                    'all_ips' => $allIps,
                    'node' => $vps['server_name'] ?? null,
                    'virt' => $vps['virt'] ?? null,
                    'email' => $email,
                    'mac' => $vps['mac'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            $safeMsg = preg_replace('/adminapikey=[^&]+/', 'adminapikey=***', $e->getMessage());
            $safeMsg = preg_replace('/adminapipass=[^&]+/', 'adminapipass=***', $safeMsg);
            Log::error("Virtualizor error: {$safeMsg}", ['ip' => $ip]);
            return null;
        }
    }
}
