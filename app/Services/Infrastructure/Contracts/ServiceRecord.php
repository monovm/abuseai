<?php

namespace App\Services\Infrastructure\Contracts;

use App\Enums\ProviderCapability;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Canonical representation of an "IP belongs to this customer / service"
 * lookup, regardless of which provider produced it. The case-detail
 * Livewire panel and the case engine read these fields; they never see
 * provider-shaped arrays. New integrations just have to populate this.
 *
 * Optional fields are nullable — a provider that doesn't track regdate
 * (e.g. Virtualizor standalone) leaves it null and the abuse-predates-
 * service guard becomes a no-op for it, which is the correct behaviour.
 */
class ServiceRecord
{
    /**
     * @param array<int, ProviderCapability> $capabilities  What actions the
     *        owning provider can perform on this service. The UI hides
     *        any button whose capability isn't in this list.
     * @param array<string, mixed> $rawFields  Provider-specific extras
     *        the operator might want to see (WHMCS custom fields, VZ
     *        node, etc.). Surfaced verbatim under "Additional Fields".
     * @param string|null $rawProvider  Stable identifier of the provider
     *        that produced this record (e.g. "whmcs_virtualizor"). Stored
     *        on the case so cross-provider migrations don't lose origin.
     */
    public function __construct(
        public readonly string $providerType,
        public readonly string $serviceId,
        public readonly ?string $clientId = null,
        public readonly ?string $hostname = null,
        public readonly ?string $ip = null,
        public readonly ?string $product = null,
        public readonly ?string $productGroup = null,
        public readonly string $status = 'unknown',     // active|suspended|terminated|stopped|unknown
        public readonly ?string $statusLabel = null,    // raw text from provider for tooltip
        public readonly ?string $userEmail = null,
        public readonly ?CarbonInterface $registeredAt = null,
        public readonly ?CarbonInterface $nextDueAt = null,
        public readonly ?string $billingCycle = null,
        public readonly ?string $amount = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $suspensionReason = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $serverName = null,
        public readonly ?string $serverIp = null,
        public readonly ?string $brandHint = null,      // brand the provider thinks owns this IP
        public readonly ?string $adminUrl = null,       // deep link into the source system
        public readonly array $capabilities = [],
        public readonly array $rawFields = [],
        public readonly ?string $rawProvider = null,
    ) {}

    public function can(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, ['suspended', 'stopped'], true);
    }

    /**
     * Sentinel "we couldn't bind this case to a customer because the abuse
     * happened before the service existed". Returns true whenever the abuse
     * demonstrably *started* before the service was registered — the IP
     * belonged to a different customer at the time the abuse began, so the
     * current owner is never matched or actioned for it, even if abuse
     * activity continued past the registration date.
     *
     * Missing values are never grounds to refuse a bind: a null abuse date
     * or null registeredAt returns false so we'd rather over-link and let
     * the agent unwind than silently drop a real abuse case.
     *
     * Compared at day granularity since WHMCS regdate is a date column.
     */
    public function abusePredates(?CarbonInterface $abuseOccurredAt): bool
    {
        if (! $abuseOccurredAt || ! $this->registeredAt) {
            return false;
        }

        return $abuseOccurredAt->copy()->startOfDay()
            ->lt($this->registeredAt->copy()->startOfDay());
    }

    /**
     * Shape used when serialising into AbuseCase.metadata so admin
     * dashboards and automation rules can match without re-running the
     * provider lookup. Stable keys; new fields only — never rename.
     */
    public function toMetadata(): array
    {
        return array_filter([
            'service_id' => $this->serviceId,
            'client_id' => $this->clientId,
            'hostname' => $this->hostname,
            'product' => $this->product,
            'status' => $this->status,
            'registered_at' => $this->registeredAt?->toIso8601String(),
            'provider' => $this->rawProvider ?? $this->providerType,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
