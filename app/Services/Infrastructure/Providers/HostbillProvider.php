<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\HostbillService;
use Carbon\Carbon;

/**
 * HostBill billing provider — high-end WHMCS competitor. Same shape:
 * accounts have a `dedicated_ip` field that's directly searchable, with
 * a paginated fallback for installs that don't surface it as a filter.
 */
class HostbillProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::Hostbill;
    }

    public function name(): string
    {
        return 'hostbill';
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

        $account = $this->client($brand)->findAccountByIp($ip);
        if (! $account || empty($account['id'])) {
            return null;
        }

        $registeredAt = null;
        if (! empty($account['date_created'])) {
            try {
                $registeredAt = Carbon::parse($account['date_created']);
            } catch (\Throwable) {
            }
        }
        $nextDueAt = null;
        if (! empty($account['next_due'])) {
            try {
                $nextDueAt = Carbon::parse($account['next_due']);
            } catch (\Throwable) {
            }
        }

        $status = match (strtolower((string) ($account['status'] ?? ''))) {
            'active' => 'active',
            'suspended', 'fraud' => 'suspended',
            'cancelled', 'terminated', 'expired' => 'terminated',
            default => $account['status'] ?? 'unknown',
        };

        $adminUrl = null;
        $cfg = $brand->hostbill_config ?? [];
        if (! empty($cfg['admin_url']) && ! empty($account['id'])) {
            $adminUrl = rtrim($cfg['admin_url'], '/') . "/?cmd=accounts&action=edit&id={$account['id']}";
        }

        return new ServiceRecord(
            providerType: ProviderType::Hostbill->value,
            serviceId: (string) $account['id'],
            clientId: isset($account['client_id']) ? (string) $account['client_id'] : null,
            hostname: $account['domain'] ?? null,
            ip: $account['dedicated_ip'] ?? $ip,
            product: $account['product_name'] ?? null,
            productGroup: $account['category'] ?? null,
            status: $status,
            statusLabel: $account['status'] ?? null,
            registeredAt: $registeredAt,
            nextDueAt: $nextDueAt,
            billingCycle: $account['billing_cycle'] ?? null,
            amount: isset($account['recurring_amount']) ? (string) $account['recurring_amount'] : null,
            paymentMethod: $account['payment_method'] ?? null,
            suspensionReason: $account['reason'] ?? null,
            orderNumber: isset($account['order_id']) ? (string) $account['order_id'] : null,
            serverName: is_array($account['server'] ?? null) ? ($account['server']['name'] ?? null) : ($account['server'] ?? null),
            adminUrl: $adminUrl,
            capabilities: $this->capabilities($brand),
            rawFields: [],
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('HostBill not configured.');
        }
        $r = $this->client($brand)->suspendAccount($service->serviceId, $reason);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'Account suspended', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Suspend failed', $r);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('HostBill not configured.');
        }
        $r = $this->client($brand)->unsuspendAccount($service->serviceId);
        return $r['success']
            ? ProviderActionResult::ok($r['message'] ?? 'Account unsuspended', $r)
            : ProviderActionResult::fail($r['message'] ?? 'Unsuspend failed', $r);
    }

    protected function client(Brand $brand): HostbillService
    {
        return new HostbillService($brand->hostbill_config);
    }
}
