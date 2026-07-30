<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\BlestaService;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use Carbon\Carbon;

/**
 * Blesta billing provider — natural fit for hosters who left WHMCS.
 * Lookup tries the cheap domain match first, then walks services
 * filtering by IP across the common module-side fields.
 */
class BlestaProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::Blesta;
    }

    public function name(): string
    {
        return 'blesta';
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

        $service = $this->client($brand)->findServiceByIp($ip);
        if (! $service || empty($service['id'])) {
            return null;
        }

        $registeredAt = null;
        if (! empty($service['date_added'])) {
            try {
                $registeredAt = Carbon::parse($service['date_added']);
            } catch (\Throwable) {
            }
        }
        $nextDueAt = null;
        if (! empty($service['date_renews'])) {
            try {
                $nextDueAt = Carbon::parse($service['date_renews']);
            } catch (\Throwable) {
            }
        }

        $rawStatus = strtolower((string) ($service['status'] ?? ''));
        $status = match ($rawStatus) {
            'active' => 'active',
            'suspended' => 'suspended',
            'cancelled', 'canceled', 'in_review' => 'terminated',
            default => $rawStatus !== '' ? $rawStatus : 'unknown',
        };

        $adminUrl = null;
        $cfg = $brand->blesta_config ?? [];
        if (! empty($cfg['admin_url']) && ! empty($service['id'])) {
            $adminUrl = rtrim($cfg['admin_url'], '/') . "/clients/view/{$service['client_id']}/services/{$service['id']}";
        }

        return new ServiceRecord(
            providerType: ProviderType::Blesta->value,
            serviceId: (string) $service['id'],
            clientId: isset($service['client_id']) ? (string) $service['client_id'] : null,
            hostname: $service['domain'] ?? null,
            ip: $ip,
            product: $service['package_name'] ?? $service['name'] ?? null,
            status: $status,
            statusLabel: $service['status'] ?? null,
            registeredAt: $registeredAt,
            nextDueAt: $nextDueAt,
            amount: isset($service['price']) ? (string) ($service['override_price'] ?? $service['price']) : null,
            adminUrl: $adminUrl,
            capabilities: $this->capabilities($brand),
            rawFields: $service['fields'] ?? [],
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('Blesta not configured.');
        }
        $r = $this->client($brand)->suspendService($service->serviceId, $reason);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'Service suspended', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Suspend failed', $r);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('Blesta not configured.');
        }
        $r = $this->client($brand)->unsuspendService($service->serviceId);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'Service unsuspended', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Unsuspend failed', $r);
    }

    protected function client(Brand $brand): BlestaService
    {
        return new BlestaService($brand->blesta_config);
    }
}
