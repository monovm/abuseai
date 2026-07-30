<?php

namespace App\Services\Infrastructure;

use App\Models\Brand;
use App\Services\Infrastructure\Contracts\ServiceRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Front door for IP → service lookups. Every consumer (case engine,
 * Livewire panel, automation jobs) goes through here so the per-brand
 * cache and provider dispatch live in exactly one place.
 *
 * No HTTP / API logic in this class — it just resolves the right
 * provider via ProviderRegistry, calls lookupByIp, and caches the
 * resulting ServiceRecord. Cache key is per-brand-per-IP so swapping a
 * brand's provider invalidates cleanly without a global purge.
 */
class InfrastructureLookupService
{
    public function __construct(
        protected ProviderRegistry $registry,
    ) {}

    public function lookup(Brand $brand, string $ip): ?ServiceRecord
    {
        $key = $this->cacheKey($brand, $ip);
        $cached = Cache::get($key);
        if ($cached instanceof ServiceRecord) {
            return $cached;
        }
        if ($cached !== null) {
            // Stale entry from an older class shape (e.g. provider refactor
            // left __PHP_Incomplete_Class behind). Drop and recompute so the
            // return type contract holds.
            Cache::forget($key);
        }
        $result = $this->doLookup($brand, $ip);
        Cache::put($key, $result, 600);
        return $result;
    }

    public function refresh(Brand $brand, string $ip): ?ServiceRecord
    {
        Cache::forget($this->cacheKey($brand, $ip));
        $result = $this->doLookup($brand, $ip);
        if ($result) {
            Cache::put($this->cacheKey($brand, $ip), $result, 600);
        }
        return $result;
    }

    /**
     * True only when both abuse_occurred_at and the service's
     * registered_at are known and the abuse demonstrably predates
     * registration. Missing values are never grounds to refuse a bind.
     *
     * Kept as a static so call sites that already have a ServiceRecord
     * (e.g. background jobs that loaded it from cache) don't have to
     * route through the lookup service again.
     */
    public static function abusePredatesService(
        ?ServiceRecord $service,
        ?CarbonInterface $abuseOccurredAt,
    ): bool {
        if (! $service) {
            return false;
        }
        return $service->abusePredates($abuseOccurredAt);
    }

    protected function doLookup(Brand $brand, string $ip): ?ServiceRecord
    {
        $provider = $this->registry->for($brand);
        if (! $provider->isConfigured($brand)) {
            return null;
        }
        return $provider->lookupByIp($brand, $ip);
    }

    protected function cacheKey(Brand $brand, string $ip): string
    {
        return "infra_lookup:{$brand->id}:{$ip}";
    }
}
