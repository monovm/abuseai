<?php

namespace App\Services\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Providers\BlestaProvider;
use App\Services\Infrastructure\Providers\GenericHttpProvider;
use App\Services\Infrastructure\Providers\HostbillProvider;
use App\Services\Infrastructure\Providers\NoneProvider;
use App\Services\Infrastructure\Providers\ProxmoxProvider;
use App\Services\Infrastructure\Providers\SolusVmProvider;
use App\Services\Infrastructure\Providers\VirtualizorProvider;
use App\Services\Infrastructure\Providers\WhmcsProvider;
use App\Services\Infrastructure\Providers\WhmcsVirtualizorProvider;

/**
 * Maps a Brand's chosen ProviderType to the live InfrastructureProvider
 * instance. Single point of dispatch — adding a new integration means
 * adding a case here and wiring its config field group into the brand
 * admin form, and that's it.
 *
 * Providers are constructed once and reused; they're stateless beyond
 * their dependencies, and per-brand state lives on the Brand model.
 */
class ProviderRegistry
{
    /** @var array<string, InfrastructureProvider> */
    protected array $instances = [];

    public function __construct(
        protected NoneProvider $none,
        protected VirtualizorProvider $virtualizor,
        protected WhmcsProvider $whmcs,
        protected WhmcsVirtualizorProvider $whmcsVirtualizor,
        protected SolusVmProvider $solusvm,
        protected ProxmoxProvider $proxmox,
        protected BlestaProvider $blesta,
        protected HostbillProvider $hostbill,
        protected GenericHttpProvider $genericHttp,
    ) {
        $this->instances = [
            ProviderType::None->value => $this->none,
            ProviderType::Virtualizor->value => $this->virtualizor,
            ProviderType::Whmcs->value => $this->whmcs,
            ProviderType::WhmcsVirtualizor->value => $this->whmcsVirtualizor,
            ProviderType::SolusVm->value => $this->solusvm,
            ProviderType::Proxmox->value => $this->proxmox,
            ProviderType::Blesta->value => $this->blesta,
            ProviderType::Hostbill->value => $this->hostbill,
            ProviderType::GenericHttp->value => $this->genericHttp,
        ];
    }

    public function for(Brand $brand): InfrastructureProvider
    {
        $type = $brand->provider_type ?? null;
        if ($type instanceof ProviderType) {
            return $this->instances[$type->value] ?? $this->none;
        }
        return $this->instances[(string) $type] ?? $this->none;
    }

    /** @return array<string, InfrastructureProvider> */
    public function all(): array
    {
        return $this->instances;
    }
}
