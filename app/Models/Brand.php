<?php

namespace App\Models;

use App\Enums\ProviderType;
use App\Services\Infrastructure\InfrastructureLookupService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Brand extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'from_name', 'from_email', 'reply_to_email',
        'email_signature', 'email_footer', 'logo_url', 'website_url',
        'is_default', 'is_active', 'smtp_config', 'whmcs_config', 'virtualizor_config',
        'hostname_pattern', 'metadata',
        'provider_type', 'generic_provider_config',
        'solusvm_config', 'proxmox_config', 'blesta_config', 'hostbill_config',
        'report_hostnames', 'report_config',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'smtp_config' => 'encrypted:array',
            'whmcs_config' => 'encrypted:array',
            'virtualizor_config' => 'encrypted:array',
            'generic_provider_config' => 'encrypted:array',
            'solusvm_config' => 'encrypted:array',
            'proxmox_config' => 'encrypted:array',
            'blesta_config' => 'encrypted:array',
            'hostbill_config' => 'encrypted:array',
            'metadata' => 'array',
            'provider_type' => ProviderType::class,
            'report_hostnames' => 'array',
            'report_config' => 'array',
        ];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(AbuseCase::class);
    }

    public function emailConnections(): HasMany
    {
        return $this->hasMany(EmailConnection::class);
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first() ?? static::active()->first();
    }

    /**
     * SMTP settings used to send from this brand's own mailbox when Mandrill
     * is down or not configured. Explicit smtp_* keys in smtp_config win;
     * anything not set falls back to the brand's active inbox connection
     * (same mail server + login the poller already uses). Returns null when
     * there is nothing usable — no explicit config and no inbox connection.
     *
     * @return array{host: string, port: int, username: string, password: string, encryption: string}|null
     */
    public function smtpFallbackConfig(): ?array
    {
        $cfg = $this->smtp_config ?? [];
        $conn = $this->emailConnections()->where('is_active', true)->first();

        $host = trim((string) ($cfg['smtp_host'] ?? $conn?->host ?? ''));
        $username = trim((string) ($cfg['smtp_username'] ?? $conn?->username ?? ''));
        $password = (string) ($cfg['smtp_password'] ?? $conn?->password ?? '');

        if ($host === '' || $username === '' || $password === '') {
            return null;
        }

        // POP3/IMAP inboxes are almost always SSL (995/993); the matching
        // SMTP convention is implicit TLS on 465, otherwise STARTTLS on 587.
        $encryption = $cfg['smtp_encryption'] ?? (($conn === null || $conn->ssl) ? 'ssl' : 'tls');
        $port = (int) ($cfg['smtp_port'] ?? ($encryption === 'ssl' ? 465 : 587));

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
        ];
    }

    /**
     * Resolve the brand whose public abuse-form hostnames include the given
     * host. Match is case-insensitive and exact (no wildcard) — to support
     * "abuse.example.com" and "report.example.com" pointing at the same
     * brand, list both in report_hostnames.
     */
    public static function findByHost(string $host): ?self
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        foreach (static::active()->whereNotNull('report_hostnames')->get() as $brand) {
            $hosts = array_map('strtolower', array_map('trim', (array) ($brand->report_hostnames ?? [])));
            if (in_array($host, $hosts, true)) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * Find the brand that owns a given IP.
     *
     * Strategy:
     *  1. Existing case for the IP with a brand_id wins — already triaged.
     *  2. Walk active brands and ask each one's configured provider until
     *     one returns a ServiceRecord. The hostname returned (if any) is
     *     then matched against every brand's hostname_pattern, so a
     *     shared-Virtualizor setup still hands the case to the right
     *     brand even if the lookup ran against a sibling brand's config.
     *  3. Default brand as last resort.
     */
    public static function findByIp(string $ip, bool $ignoreExistingCase = false): ?self
    {
        if (! $ignoreExistingCase) {
            $existingCase = AbuseCase::where('target_ip', $ip)->whereNotNull('brand_id')->latest()->first();
            if ($existingCase?->brand_id) {
                return static::find($existingCase->brand_id);
            }
        }

        try {
            $lookup = app(InfrastructureLookupService::class);
            foreach (static::active()->get() as $brand) {
                if (($brand->provider_type ?? null) === ProviderType::None) {
                    continue;
                }
                $record = $lookup->lookup($brand, $ip);
                if (! $record) {
                    continue;
                }

                $hostname = strtolower((string) ($record->hostname ?? ''));
                if ($hostname) {
                    $matched = static::matchHostnamePattern($hostname);
                    if ($matched) {
                        return $matched;
                    }
                }

                // Provider answered but no hostname pattern matched — assume
                // the brand whose provider produced the hit owns the IP.
                return $brand;
            }
        } catch (\Throwable $e) {
            Log::info('Brand::findByIp lookup failed; falling back to default', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return static::getDefault();
    }

    /**
     * Find a brand whose hostname_pattern matches the actual provider hostname
     * for this IP. Definitive — only returns when a pattern matches; the
     * caller decides what to do on null. Used as a high-priority signal that
     * beats recipient-based matching, since hostname comes from authoritative
     * infrastructure lookup while recipient only shows where the mail landed.
     */
    public static function findByHostnamePatternForIp(string $ip): ?self
    {
        $lookup = app(InfrastructureLookupService::class);
        $hostnamesSeen = [];

        foreach (static::active()->get() as $brand) {
            if (($brand->provider_type ?? null) === ProviderType::None) {
                continue;
            }
            try {
                $record = $lookup->lookup($brand, $ip);
            } catch (\Throwable $e) {
                Log::info('Brand::findByHostnamePatternForIp lookup error', [
                    'ip' => $ip,
                    'queried_brand' => $brand->name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            $hostname = strtolower((string) ($record?->hostname ?? ''));
            if ($hostname === '') {
                continue;
            }
            $hostnamesSeen[$brand->name] = $hostname;
            $matched = static::matchHostnamePattern($hostname);
            if ($matched) {
                Log::info('Brand::findByHostnamePatternForIp matched', [
                    'ip' => $ip,
                    'queried_brand' => $brand->name,
                    'hostname' => $hostname,
                    'matched_brand' => $matched->name,
                ]);
                return $matched;
            }
        }

        if (! empty($hostnamesSeen)) {
            Log::info('Brand::findByHostnamePatternForIp no pattern match', [
                'ip' => $ip,
                'hostnames_seen' => $hostnamesSeen,
                'patterns' => static::active()->whereNotNull('hostname_pattern')
                    ->pluck('hostname_pattern', 'name')->toArray(),
            ]);
        }

        return null;
    }

    protected static function matchHostnamePattern(string $hostname): ?self
    {
        return static::active()->whereNotNull('hostname_pattern')->get()
            ->first(function ($candidate) use ($hostname) {
                $patterns = array_map('trim', explode(',', strtolower($candidate->hostname_pattern)));
                foreach ($patterns as $p) {
                    if ($p && str_contains($hostname, $p)) {
                        return true;
                    }
                }
                return false;
            });
    }
}
