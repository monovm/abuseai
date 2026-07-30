<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\SolusVmService;
use Carbon\Carbon;

/**
 * SolusVM v1 panel provider. The VPS is the "service" — vserverid is
 * the service id, the client record on it gives us a customer email and
 * id without a separate billing call.
 */
class SolusVmProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::SolusVm;
    }

    public function name(): string
    {
        return 'solusvm';
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

        $vps = $this->client($brand)->resolveIp($ip);
        if (! $vps || empty($vps['vserverid'])) {
            return null;
        }

        $registeredAt = null;
        if (! empty($vps['created'])) {
            try {
                $registeredAt = Carbon::parse($vps['created']);
            } catch (\Throwable) {
            }
        }

        $status = ($vps['suspended'] ?? false)
            ? 'suspended'
            : (in_array(($vps['state'] ?? null), ['online', 'running'], true) ? 'active' : ($vps['state'] ?? 'unknown'));

        return new ServiceRecord(
            providerType: ProviderType::SolusVm->value,
            serviceId: (string) $vps['vserverid'],
            clientId: isset($vps['clientid']) ? (string) $vps['clientid'] : null,
            hostname: $vps['hostname'] ?? null,
            ip: $vps['mainipaddress'] ?? $ip,
            product: $vps['plan'] ?? null,
            productGroup: $vps['type'] ?? null,
            status: $status,
            statusLabel: $vps['state'] ?? null,
            userEmail: $vps['clientemail'] ?? null,
            registeredAt: $registeredAt,
            serverName: $vps['node'] ?? null,
            capabilities: $this->capabilities($brand),
            rawFields: array_filter([
                'Plan' => $vps['plan'] ?? null,
                'Type' => $vps['type'] ?? null,
                'Disk' => $vps['hdd'] ?? null,
                'Memory' => $vps['mem'] ?? null,
                'Bandwidth' => $vps['bw'] ?? null,
                'Client Name' => $vps['clientname'] ?? null,
            ]),
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('SolusVM not configured.');
        }
        $r = $this->client($brand)->suspend($service->serviceId, $reason);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'VPS suspended', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Suspend failed', $r);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('SolusVM not configured.');
        }
        $r = $this->client($brand)->unsuspend($service->serviceId);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'VPS unsuspended', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Unsuspend failed', $r);
    }

    protected function client(Brand $brand): SolusVmService
    {
        return new SolusVmService($brand->solusvm_config);
    }
}
