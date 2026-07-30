<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reporter extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'type',
        'api_key',
        'reputation_score',
        'report_count',
        'is_trusted',
        'is_blocked',
        'is_law_enforcement',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reputation_score' => 'decimal:2',
            'is_trusted' => 'boolean',
            'is_blocked' => 'boolean',
            'is_law_enforcement' => 'boolean',
            'metadata' => 'array',
            // api_key is stored as a bcrypt hash — hashing done manually in middleware
        ];
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AbuseReport::class);
    }

    public function scopeTrusted($query)
    {
        return $query->where('is_trusted', true);
    }

    public function scopeNotBlocked($query)
    {
        return $query->where('is_blocked', false);
    }

    public function scopeLawEnforcement($query)
    {
        return $query->where('is_law_enforcement', true);
    }

    /**
     * Does this email domain (or its parents) match the configured LE allowlist?
     * Patterns are case-insensitive; `*.police.uk` style wildcards are supported.
     */
    public static function emailMatchesLawEnforcement(string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = str_contains($email, '@') ? substr($email, strpos($email, '@') + 1) : $email;
        if (! $domain) {
            return false;
        }

        $patterns = (array) config('abusedesk.law_enforcement.reporter_domains', []);
        foreach ($patterns as $raw) {
            $pattern = strtolower(trim($raw));
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 2);
                if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                    return true;
                }
                continue;
            }
            if ($domain === $pattern || str_ends_with($domain, '.' . $pattern)) {
                return true;
            }
        }
        return false;
    }
}
