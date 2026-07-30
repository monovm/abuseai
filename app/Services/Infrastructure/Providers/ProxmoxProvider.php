<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\ProxmoxService;

/**
 * Proxmox VE provider. Suspend/unsuspend map to stop/start respectively
 * — Proxmox's "suspend" verb is a paused-RAM state, which isn't what
 * abuse handlers want; stopping the VM is the safer default.
 *
 * Proxmox doesn't track customer ownership at the cluster level, so
 * clientId stays null and the case engine won't auto-link a customer
 * for Proxmox-only brands. Operators link manually or layer Blesta /
 * HostBill on top via a future combined provider.
 */
class ProxmoxProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::Proxmox;
    }

    public function name(): string
    {
        return 'proxmox';
    }

    public function isConfigured(Brand $brand): bool
    {
        return $this->client($brand)->isConfigured();
    }

    public function capabilities(Brand $brand): array
    {
        if (! $this->isConfigured($brand)) {
            return [];
        }
        return [
            ProviderCapability::Lookup,
            ProviderCapability::Suspend,
            ProviderCapability::Unsuspend,
        ];
    }

    public function lookupByIp(Brand $brand, string $ip): ?ServiceRecord
    {
        if (! $this->isConfigured($brand)) {
            return null;
        }

        $vm = $this->client($brand)->resolveIp($ip);
        if (! $vm || empty($vm['vmid'])) {
            return null;
        }

        $status = match (strtolower((string) ($vm['status'] ?? ''))) {
            'running', 'online' => 'active',
            'stopped', 'paused', 'suspended' => 'suspended',
            default => $vm['status'] ?? 'unknown',
        };

        return new ServiceRecord(
            providerType: ProviderType::Proxmox->value,
            serviceId: (string) $vm['vmid'],
            clientId: null,                       // Proxmox doesn't model customers
            hostname: $vm['hostname'] ?? null,
            ip: $ip,
            product: $vm['type'] ?? null,         // qemu | lxc
            status: $status,
            statusLabel: $vm['status'] ?? null,
            serverName: $vm['node'] ?? null,
            capabilities: $this->capabilities($brand),
            rawFields: array_filter([
                'CPU cores' => $vm['cpu'] ?? null,
                'Memory (bytes)' => $vm['mem_bytes'] ?? null,
                'Disk (bytes)' => $vm['disk_bytes'] ?? null,
                'Tags' => $vm['tags'] ?? null,
                'Description' => $vm['description'] ?? null,
            ]),
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('Proxmox not configured.');
        }
        // We need node + kind to talk to the VM — pulled from rawFields
        // when available, otherwise re-fetched via cluster/resources.
        [$node, $kind] = $this->locateVm($brand, $service);
        if (! $node || ! $kind) {
            return ProviderActionResult::fail('Could not locate VM in cluster.');
        }
        $r = $this->client($brand)->stop($node, $kind, $service->serviceId);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'VM stopped', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Stop failed', $r);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('Proxmox not configured.');
        }
        [$node, $kind] = $this->locateVm($brand, $service);
        if (! $node || ! $kind) {
            return ProviderActionResult::fail('Could not locate VM in cluster.');
        }
        $r = $this->client($brand)->start($node, $kind, $service->serviceId);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'VM started', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Start failed', $r);
    }

    /**
     * @return array{0: ?string, 1: ?string}  [node, kind]
     */
    protected function locateVm(Brand $brand, ServiceRecord $service): array
    {
        $node = $service->serverName ?? null;
        $kind = $service->product ?? null;
        if ($node && $kind && in_array($kind, ['qemu', 'lxc'], true)) {
            return [$node, $kind];
        }
        // Re-derive from a fresh lookup if the record didn't carry it.
        if ($service->ip) {
            $fresh = $this->client($brand)->resolveIp($service->ip);
            if ($fresh) {
                return [$fresh['node'] ?? null, $fresh['type'] ?? null];
            }
        }
        return [null, null];
    }

    protected function client(Brand $brand): ProxmoxService
    {
        return new ProxmoxService($brand->proxmox_config);
    }
}
