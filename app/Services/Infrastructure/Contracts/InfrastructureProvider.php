<?php

namespace App\Services\Infrastructure\Contracts;

use App\Enums\ProviderCapability;
use App\Enums\ProviderType;
use App\Models\Brand;

/**
 * Pluggable IP → service integration. Implementations live in
 * App\Services\Infrastructure\Providers and are registered against a
 * ProviderType in ProviderRegistry.
 *
 * The contract is intentionally tight: lookup returns one canonical DTO
 * (or null), and any operator action returns a ProviderActionResult.
 * Callers never inspect provider-shaped arrays — that's the whole point
 * of this layer.
 */
interface InfrastructureProvider
{
    public function type(): ProviderType;

    /**
     * Stable identifier written into ServiceRecord::$rawProvider and
     * AbuseCase metadata. Used for audit-log filtering and to keep older
     * cases attributable after a brand swaps providers.
     */
    public function name(): string;

    public function isConfigured(Brand $brand): bool;

    /** @return array<int, ProviderCapability> */
    public function capabilities(Brand $brand): array;

    public function lookupByIp(Brand $brand, string $ip): ?ServiceRecord;

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult;

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult;
}
