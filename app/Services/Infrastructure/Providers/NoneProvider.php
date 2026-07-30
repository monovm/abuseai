<?php

namespace App\Services\Infrastructure\Providers;

use App\Enums\ProviderType;
use App\Models\Brand;
use App\Services\Infrastructure\Contracts\InfrastructureProvider;
use App\Services\Infrastructure\Contracts\ProviderActionResult;
use App\Services\Infrastructure\Contracts\ServiceRecord;

/**
 * No-op provider for brands that don't have a panel or billing system
 * we can talk to. Lookup always returns null and every action is a soft
 * failure with a clear "not configured" message — no throw, no surprise.
 */
class NoneProvider implements InfrastructureProvider
{
    public function type(): ProviderType
    {
        return ProviderType::None;
    }

    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(Brand $brand): bool
    {
        return true;
    }

    public function capabilities(Brand $brand): array
    {
        return [];
    }

    public function lookupByIp(Brand $brand, string $ip): ?ServiceRecord
    {
        return null;
    }

    public function suspend(Brand $brand, ServiceRecord $service, string $reason): ProviderActionResult
    {
        return ProviderActionResult::fail('No infrastructure provider configured for this brand.');
    }

    public function unsuspend(Brand $brand, ServiceRecord $service): ProviderActionResult
    {
        return ProviderActionResult::fail('No infrastructure provider configured for this brand.');
    }
}
