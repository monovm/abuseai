<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Enums\WhmcsMatchStrategy;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use App\Services\Infrastructure\VirtualizorService;
use App\Services\Infrastructure\WhmcsService;
use Illuminate\Support\Facades\Log;

/**
 * Combined mode: Virtualizor finds the VPS for an IP, then WHMCS looks
 * up the matching service by hostname. This is the legacy default for
 * brands that have both panels — the original InfrastructureLookupService
 * behaviour, ported here without behavioural change.
 *
 * Falls back to whmcs-only behaviour if Virtualizor is unconfigured but
 * WHMCS is, so a brand mid-migration doesn't black-screen the UI.
 */
class WhmcsVirtualizorProvider implements InfrastructureProvider
{
    public function __construct(
        protected WhmcsProvider $whmcsProvider,
        protected VirtualizorProvider $virtualizorProvider,
    ) {}

    public function type(): ProviderType
    {
        return ProviderType::WhmcsVirtualizor;
    }

    public function name(): string
    {
        return 'whmcs_virtualizor';
    }

    public function isConfigured(Brand $brand): bool
    {
        return $this->whmcsProvider->isConfigured($brand)
            || $this->virtualizorProvider->isConfigured($brand);
    }

    public function capabilities(Brand $brand): array
    {
        $caps = [];
        if ($this->virtualizorProvider->isConfigured($brand)) {
            $caps[] = ProviderCapability::Lookup;
        }
        if ($this->whmcsProvider->isConfigured($brand)) {
            $caps = array_merge($caps, [
                ProviderCapability::Lookup,
                ProviderCapability::Suspend,
                ProviderCapability::Unsuspend,
                ProviderCapability::OpenTicket,
            ]);
        }
        return array_values(array_unique($caps, SORT_REGULAR));
    }

    public function lookupByIp(Brand $brand, string $ip): ?ServiceRecord
    {
        $vzCfg = $brand->virtualizor_config ?? [];
        $vzConfigured = ! empty($vzCfg['url']) && ! empty($vzCfg['api_key']) && ! empty($vzCfg['api_pass']);

        if (! $vzConfigured) {
            // Mid-migration brand with WHMCS only — degrade gracefully.
            return $this->whmcsProvider->lookupByIp($brand, $ip);
        }

        $vz = new VirtualizorService($vzCfg);
        $vps = $vz->resolveIp($ip);
        if (! $vps || empty($vps['vpsid'])) {
            return null;
        }

        $hostname = $vps['vps']['hostname'] ?? '';
        $whmcs = new WhmcsService($brand->whmcs_config);

        // Pick the join key per the brand's configured strategy. Default
        // is hostname→domain, which matches the legacy hardcoded path.
        $strategy = $this->resolveStrategy($brand);
        $sourceValue = match ($strategy->vzSource()) {
            'hostname' => $hostname,
            'ip' => $ip,
        };

        $service = null;
        if ($sourceValue !== '' && $whmcs->isConfigured()) {
            $service = $whmcs->findServiceBy($strategy->whmcsTarget(), $sourceValue);
            if (! $service) {
                Log::info('WhmcsVirtualizorProvider: no WHMCS match for strategy', [
                    'ip' => $ip,
                    'strategy' => $strategy->value,
                    'source_value' => $sourceValue,
                ]);
            }
        }

        // Build the merged record. WHMCS data wins where both providers
        // overlap — billing is more authoritative than the panel for
        // ownership / regdate / status; Virtualizor adds live VPS state.
        $vpsData = $vps['vps'] ?? [];
        $userEmail = $vps['email'] ?? null;
        $rawFields = [
            'VPS ID' => $vps['vpsid'] ?? null,
            'VPS Status' => $vpsData['status'] ?? null,
            'OS' => $vpsData['os_name'] ?? null,
            'RAM (MB)' => $vpsData['ram'] ?? null,
            'Disk (GB)' => $vpsData['disk'] ?? null,
            'Node' => $vpsData['node'] ?? null,
            'Virtualization' => $vpsData['virt'] ?? null,
            'User Email' => $userEmail,
        ];

        if ($service) {
            $whmcsRecord = $this->whmcsProvider->recordFromWhmcs($brand, $service, $ip, $hostname);
            return new ServiceRecord(
                providerType: $this->type()->value,
                serviceId: $whmcsRecord->serviceId,
                clientId: $whmcsRecord->clientId,
                hostname: $hostname ?: $whmcsRecord->hostname,
                ip: $ip,
                product: $whmcsRecord->product,
                productGroup: $whmcsRecord->productGroup,
                status: $whmcsRecord->status,
                statusLabel: $whmcsRecord->statusLabel,
                userEmail: $userEmail,
                registeredAt: $whmcsRecord->registeredAt,
                nextDueAt: $whmcsRecord->nextDueAt,
                billingCycle: $whmcsRecord->billingCycle,
                amount: $whmcsRecord->amount,
                paymentMethod: $whmcsRecord->paymentMethod,
                suspensionReason: $whmcsRecord->suspensionReason ?? ($vpsData['suspend_reason'] ?? null),
                orderNumber: $whmcsRecord->orderNumber,
                serverName: $whmcsRecord->serverName ?? ($vpsData['node'] ?? null),
                serverIp: $whmcsRecord->serverIp,
                adminUrl: $whmcsRecord->adminUrl,
                capabilities: $this->capabilities($brand),
                rawFields: array_filter(array_merge($rawFields, $whmcsRecord->rawFields)),
                rawProvider: $this->name(),
            );
        }

        // VPS exists but WHMCS didn't match — return a Virtualizor-only
        // record so the panel still has something to show. (The "why"
        // was already logged above with the active strategy.)
        return new ServiceRecord(
            providerType: $this->type()->value,
            serviceId: (string) $vps['vpsid'],
            clientId: isset($vps['uid']) ? (string) $vps['uid'] : null,
            hostname: $hostname ?: null,
            ip: $ip,
            product: $vpsData['vps_name'] ?? null,
            productGroup: $vpsData['virt'] ?? null,
            status: ($vpsData['suspended'] ?? false) ? 'suspended' : 'active',
            statusLabel: $vpsData['status'] ?? null,
            userEmail: $userEmail,
            suspensionReason: $vpsData['suspend_reason'] ?? null,
            serverName: $vpsData['node'] ?? null,
            capabilities: array_values(array_filter(
                $this->capabilities($brand),
                fn ($c) => $c === ProviderCapability::Lookup,
            )),
            rawFields: array_filter($rawFields),
            rawProvider: $this->name(),
        );
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        return $this->whmcsProvider->suspend($brand, $service, $reason);
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        return $this->whmcsProvider->unsuspend($brand, $service);
    }

    /**
     * Resolve the brand's chosen WHMCS match strategy, defaulting to the
     * legacy hostname→domain pivot when nothing is configured.
     */
    protected function resolveStrategy(Brand $brand): WhmcsMatchStrategy
    {
        $raw = $brand->whmcs_config['match_strategy'] ?? null;
        return WhmcsMatchStrategy::tryFrom((string) $raw) ?? WhmcsMatchStrategy::default();
    }
}
