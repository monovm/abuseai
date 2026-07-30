<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\VirtualizorService;

/**
 * Virtualizor-only mode: VPS panel without an attached billing system.
 *
 * The VPS itself is the "service" — vpsid is treated as the service ID
 * and the panel user (uid) is the client ID. There's no regdate (the
 * panel doesn't track ownership history), so the abuse-predates-service
 * guard is correctly inert for this mode.
 *
 * Suspend/unsuspend is left as a TODO action surface here — Virtualizor
 * has start/stop/suspend endpoints but plumbing them in is out of scope
 * for this refactor; we declare the capability absent so the UI hides
 * the buttons rather than promising something we haven't wired.
 */
class VirtualizorProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::Virtualizor;
    }

    public function name(): string
    {
        return 'virtualizor';
    }

    public function isConfigured(Brand $brand): bool
    {
        $cfg = $brand->virtualizor_config ?? [];
        return ! empty($cfg['url']) && ! empty($cfg['api_key']) && ! empty($cfg['api_pass']);
    }

    public function capabilities(Brand $brand): array
    {
        if (! $this->isConfigured($brand)) {
            return [];
        }
        // Lookup only — suspend/unsuspend wiring is intentionally not
        // exposed yet; agents can stop the VPS in Virtualizor directly.
        return [ProviderCapability::Lookup];
    }

    public function lookupByIp(Brand $brand, string $ip): ?ServiceRecord
    {
        if (! $this->isConfigured($brand)) {
            return null;
        }

        $vz = new VirtualizorService($brand->virtualizor_config);
        $data = $vz->resolveIp($ip);

        if (! $data || empty($data['vpsid'])) {
            return null;
        }

        $vps = $data['vps'] ?? [];

        return new ServiceRecord(
            providerType: ProviderType::Virtualizor->value,
            serviceId: (string) $data['vpsid'],
            clientId: isset($data['uid']) ? (string) $data['uid'] : null,
            hostname: $vps['hostname'] ?? null,
            ip: $ip,
            product: $vps['vps_name'] ?? null,
            productGroup: $vps['virt'] ?? null,
            status: ($vps['suspended'] ?? false) ? 'suspended' : 'active',
            statusLabel: $vps['status'] ?? null,
            userEmail: $data['email'] ?? null,
            registeredAt: null, // VZ doesn't track this
            suspensionReason: $vps['suspend_reason'] ?? null,
            serverName: $vps['node'] ?? null,
            capabilities: $this->capabilities($brand),
            rawFields: [
                'OS' => $vps['os_name'] ?? null,
                'RAM (MB)' => $vps['ram'] ?? null,
                'Disk (GB)' => $vps['disk'] ?? null,
                'CPU cores' => $vps['cpu'] ?? null,
                'Bandwidth' => $vps['bandwidth'] ?? null,
                'Used Bandwidth' => $vps['used_bandwidth'] ?? null,
                'Virtualization' => $vps['virt'] ?? null,
            ],
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        return ProviderActionResult::fail('Virtualizor suspend not wired in this provider — stop the VPS from the Virtualizor panel.');
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        return ProviderActionResult::fail('Virtualizor unsuspend not wired in this provider.');
    }
}
