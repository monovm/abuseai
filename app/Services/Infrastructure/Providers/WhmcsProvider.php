<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\WhmcsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * WHMCS-only mode: billing system without a panel pivot.
 *
 * IP → service is resolved directly through WHMCS (`dedicatedip` lookup).
 * Lacks any Virtualizor metadata (no node, no live VPS state) — the case
 * UI shows everything WHMCS knows and just hides the VPS panel.
 */
class WhmcsProvider implements InfrastructureProvider
{
    public function __construct(
        protected ?WhmcsService $whmcs = null,
    ) {}

    public function type(): ProviderType
    {
        return ProviderType::Whmcs;
    }

    public function name(): string
    {
        return 'whmcs';
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
            ProviderCapability::OpenTicket,
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

        return $this->recordFromWhmcs($brand, $service, $ip, vpsHostname: null);
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('WHMCS not configured.');
        }
        $result = $this->client($brand)->suspendService((int) $service->serviceId, $reason);
        return ($result['success'] ?? false)
            ? ProviderActionResult::ok($result['message'] ?? 'Service suspended', $result)
            : ProviderActionResult::fail($result['message'] ?? 'Suspend failed', $result);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        if (! $this->isConfigured($brand)) {
            return ProviderActionResult::fail('WHMCS not configured.');
        }
        $result = $this->client($brand)->unsuspendService((int) $service->serviceId);
        return ($result['success'] ?? false)
            ? ProviderActionResult::ok($result['message'] ?? 'Service unsuspended', $result)
            : ProviderActionResult::fail($result['message'] ?? 'Unsuspend failed', $result);
    }

    /**
     * Build a ServiceRecord from a shaped WhmcsService::findService* result.
     * Shared with WhmcsVirtualizorProvider so the two modes report the same
     * data shape — the only difference is which path produced $service.
     */
    public function recordFromWhmcs(Brand $brand, array $service, string $ip, ?string $vpsHostname): ServiceRecord
    {
        $registeredAt = null;
        if (! empty($service['regdate']) && $service['regdate'] !== '0000-00-00') {
            try {
                $registeredAt = Carbon::parse($service['regdate']);
            } catch (\Throwable $e) {
                Log::info('WhmcsProvider: unparseable regdate', [
                    'value' => $service['regdate'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $nextDueAt = null;
        if (! empty($service['nextduedate']) && $service['nextduedate'] !== '0000-00-00') {
            try {
                $nextDueAt = Carbon::parse($service['nextduedate']);
            } catch (\Throwable) {
            }
        }

        $rawStatus = strtolower((string) ($service['status'] ?? ''));
        $normalisedStatus = match ($rawStatus) {
            'active' => 'active',
            'suspended' => 'suspended',
            'terminated', 'cancelled' => 'terminated',
            default => $rawStatus !== '' ? $rawStatus : 'unknown',
        };

        $adminUrl = null;
        $whmcsCfg = $brand->whmcs_config ?? [];
        $base = rtrim($whmcsCfg['admin_url'] ?? '', '/');
        if (! $base && ! empty($whmcsCfg['url'])) {
            $base = rtrim(str_replace('/includes/api.php', '/admin', $whmcsCfg['url']), '/');
        }
        if ($base && ! empty($service['id'])) {
            $adminUrl = "{$base}/clientsservices.php?userid={$service['client_id']}&id={$service['id']}";
        }

        return new ServiceRecord(
            providerType: $this->type()->value,
            serviceId: (string) $service['id'],
            clientId: isset($service['client_id']) ? (string) $service['client_id'] : null,
            hostname: $vpsHostname ?? ($service['domain'] ?? null),
            ip: $service['dedicatedip'] ?? $ip,
            product: $service['name'] ?? null,
            productGroup: $service['groupname'] ?? null,
            status: $normalisedStatus,
            statusLabel: $service['status'] ?? null,
            userEmail: null,
            registeredAt: $registeredAt,
            nextDueAt: $nextDueAt,
            billingCycle: $service['billingcycle'] ?? null,
            amount: $service['amount'] ?? null,
            paymentMethod: $service['paymentmethod'] ?? null,
            suspensionReason: $service['suspensionreason'] ?? null,
            orderNumber: $service['ordernumber'] ?? null,
            serverName: $service['servername'] ?? null,
            serverIp: $service['serverip'] ?? null,
            adminUrl: $adminUrl,
            capabilities: $this->capabilities($brand),
            rawFields: $service['custom_fields'] ?? [],
            rawProvider: $this->name(),
        );
    }

    protected function client(Brand $brand): WhmcsService
    {
        return $this->whmcs ?? new WhmcsService($brand->whmcs_config);
    }
}
