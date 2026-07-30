<?php

namespace App\Models;

use App\Support\IpHelpers;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class IpAddress extends Model
{
    use HasFactory, HasUuids;

    public const CACHE_KEY_BLOCKS = 'ip_inventory:active_blocks:v2';

    protected $fillable = [
        'ip_address',
        'prefix_length',
        'server_name',
        'customer_id',
        'range_source',
        'status',
        'tags',
        'notes',
        'abuseipdb_score',
        'abuseipdb_reports',
        'abuseipdb_data',
        'abuseipdb_checked_at',
        'reputation_score',
        'reputation_details',
        'reputation_updated_at',
        'case_created_from_reputation',
    ];

    protected function casts(): array
    {
        return [
            'prefix_length' => 'integer',
            'tags' => 'array',
            'abuseipdb_data' => 'array',
            'abuseipdb_checked_at' => 'datetime',
            'reputation_score' => 'decimal:2',
            'reputation_details' => 'array',
            'reputation_updated_at' => 'datetime',
            'case_created_from_reputation' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY_BLOCKS));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY_BLOCKS));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }

    public function scopeReported($query)
    {
        return $query->where('abuseipdb_score', '>', 0);
    }

    public function scopeLowReputation($query, float $threshold = 30)
    {
        return $query->where('reputation_score', '<', $threshold);
    }

    public function scopeHealthy($query, float $threshold = 70)
    {
        return $query->where('reputation_score', '>=', $threshold);
    }

    public function getReputationLabelAttribute(): string
    {
        $score = (float) ($this->reputation_score ?? 100);
        if ($score >= 80) return 'Good';
        if ($score >= 50) return 'Fair';
        if ($score >= 30) return 'Poor';
        return 'Critical';
    }

    public function getReputationColorAttribute(): string
    {
        $score = (float) ($this->reputation_score ?? 100);
        if ($score >= 80) return '#10b981';
        if ($score >= 50) return '#f59e0b';
        if ($score >= 30) return '#f97316';
        return '#dc2626';
    }

    public function scopeNeverChecked($query)
    {
        return $query->whereNull('abuseipdb_checked_at');
    }

    public function scopeStaleCheck($query, int $hours = 24)
    {
        return $query->where('abuseipdb_checked_at', '<', now()->subHours($hours));
    }

    /**
     * Rows that represent a block (CIDR) rather than a single host.
     * Host-width (/32 for v4, /128 for v6) rows are excluded.
     */
    public function scopeBlocks($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($q) {
                $q->where('ip_address', 'like', '%:%')->where('prefix_length', '<', 128);
            })->orWhere(function ($q) {
                $q->where('ip_address', 'not like', '%:%')->where('prefix_length', '<', 32);
            });
        });
    }

    public function isBlock(): bool
    {
        $host = IpHelpers::hostPrefixFor($this->ip_address ?? '');
        return $host !== null && (int) $this->prefix_length < $host;
    }

    public function getScopeLabelAttribute(): string
    {
        $host = IpHelpers::hostPrefixFor($this->ip_address ?? '');
        if ($host === null) {
            return (string) $this->ip_address;
        }
        return $this->prefix_length === $host
            ? 'host'
            : "/{$this->prefix_length}";
    }

    public static function isOurs(string $ip): bool
    {
        $canonical = IpHelpers::canonicalize($ip) ?? $ip;
        $hostWidth = IpHelpers::hostPrefixFor($canonical);

        // Fast path: exact host entry.
        $exactQuery = static::where('ip_address', $canonical)->where('status', 'active');
        if ($hostWidth !== null) {
            $exactQuery->where('prefix_length', $hostWidth);
        }
        if ($exactQuery->exists()) {
            return true;
        }

        // Slow path: scan active blocks for containment.
        foreach (static::activeBlocks() as $block) {
            if (IpHelpers::ipInCidr($canonical, $block->ip_address, (int) $block->prefix_length)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve an IP to the most specific entry we own: exact host first,
     * otherwise the narrowest enclosing block.
     */
    public static function findByIp(string $ip): ?static
    {
        $canonical = IpHelpers::canonicalize($ip) ?? $ip;
        $hostWidth = IpHelpers::hostPrefixFor($canonical);

        $exactQuery = static::where('ip_address', $canonical);
        if ($hostWidth !== null) {
            $exactQuery->where('prefix_length', $hostWidth);
        }
        $exact = $exactQuery->first();
        if ($exact) {
            return $exact;
        }

        $bestId = null;
        $bestPrefix = -1;
        foreach (static::activeBlocks() as $block) {
            $prefix = (int) $block->prefix_length;
            if (! IpHelpers::ipInCidr($canonical, (string) $block->ip_address, $prefix)) {
                continue;
            }
            if ($prefix > $bestPrefix) {
                $bestPrefix = $prefix;
                $bestId = (string) $block->id;
            }
        }

        return $bestId ? static::find($bestId) : null;
    }

    /**
     * Cached list of active CIDR blocks for in-process containment checks.
     *
     * Caches *primitives* (stdClass with id/ip_address/prefix_length), not
     * Eloquent models — a serialized Eloquent Collection can deserialize as
     * __PHP_Incomplete_Class after any code change that affects the model's
     * class hierarchy, which broke the mailbox poller on every run. The
     * returning caller hydrates a real model via static::find() if it needs
     * the full row.
     */
    public static function activeBlocks(): \Illuminate\Support\Collection
    {
        $rows = Cache::remember(self::CACHE_KEY_BLOCKS, 300, function () {
            return static::query()
                ->active()
                ->blocks()
                ->get(['id', 'ip_address', 'prefix_length'])
                ->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'ip_address' => (string) $row->ip_address,
                    'prefix_length' => (int) $row->prefix_length,
                ])
                ->all();
        });

        // Defensive: if the cache was poisoned by a prior deploy (class shape
        // change causing __PHP_Incomplete_Class), toss it and rebuild.
        if (! is_array($rows) || (isset($rows[0]) && ! is_array($rows[0]))) {
            Cache::forget(self::CACHE_KEY_BLOCKS);
            return static::activeBlocks();
        }

        return collect($rows)->map(fn ($row) => (object) $row);
    }
}
