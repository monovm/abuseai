<?php

namespace App\Services\Ingestion\Feeds;

use App\Support\IpHelpers;
use Illuminate\Support\Facades\Log;

/**
 * DNSBL (DNS-based Blackhole List) lookup service.
 * Checks IPs against CBL, PSBL, Spamhaus, and other DNSBLs.
 *
 * IMPORTANT: Spamhaus and other DNSBLs have query limits.
 * 127.255.255.254 = rate limited / query refused (NOT a real listing)
 * 127.255.255.255 = general error response
 */
class DnsblService
{
    /**
     * DNSBLs to check. Using only ZEN (combines SBL+XBL+PBL) to reduce queries.
     * Checking SBL, XBL, and ZEN separately triples the queries for no benefit.
     */
    protected array $lists = [
        'spamhaus_zen' => 'zen.spamhaus.org',    // Combines SBL + XBL + PBL
        'cbl' => 'cbl.abuseat.org',
        'psbl' => 'psbl.surriel.com',
        'barracuda' => 'b.barracudacentral.org',
        'spamcop' => 'bl.spamcop.net',
    ];

    /**
     * Return codes that mean "rate limited" or "error" — NOT real listings.
     */
    protected array $falsePositiveCodes = [
        '127.255.255.254',  // Spamhaus: query limit exceeded
        '127.255.255.255',  // General error
        '127.0.0.255',      // Test/error response
    ];

    /**
     * Delay between DNS queries in microseconds (to avoid rate limiting).
     * 200ms = ~5 queries/second = safe for most DNSBLs.
     */
    protected int $queryDelayUs = 200000;

    /**
     * Check a single IP against all configured DNSBLs.
     * Returns array of REAL listings (filters out rate-limit responses).
     */
    public function checkIp(string $ip): array
    {
        if (! IpHelpers::isValid($ip)) {
            return [];
        }

        $reversed = $this->reverseIp($ip);
        if ($reversed === null) {
            return [];
        }
        $results = [];

        foreach ($this->lists as $name => $zone) {
            $lookup = "{$reversed}.{$zone}";

            try {
                $dns = @dns_get_record($lookup, DNS_A);

                if (! empty($dns)) {
                    $returnCode = $dns[0]['ip'] ?? '';

                    // Skip false positives from rate limiting
                    if ($this->isFalsePositive($returnCode)) {
                        Log::debug("DNSBL rate-limited for {$ip} on {$name} (code: {$returnCode})");
                        continue;
                    }

                    $results[] = [
                        'list' => $name,
                        'zone' => $zone,
                        'listed' => true,
                        'return_code' => $returnCode,
                        'meaning' => $this->interpretReturnCode($name, $returnCode),
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug("DNSBL lookup failed for {$lookup}: {$e->getMessage()}");
            }

            // Throttle between queries
            usleep($this->queryDelayUs);
        }

        return $results;
    }

    /**
     * Check a single IP against a specific DNSBL.
     */
    public function checkIpAgainst(string $ip, string $listName): ?array
    {
        if (! isset($this->lists[$listName])) {
            return null;
        }

        $reversed = $this->reverseIp($ip);
        if ($reversed === null) {
            return null;
        }
        $zone = $this->lists[$listName];
        $lookup = "{$reversed}.{$zone}";

        try {
            $dns = @dns_get_record($lookup, DNS_A);

            if (! empty($dns)) {
                $returnCode = $dns[0]['ip'] ?? '';

                if ($this->isFalsePositive($returnCode)) {
                    return ['list' => $listName, 'zone' => $zone, 'listed' => false, 'rate_limited' => true];
                }

                return [
                    'list' => $listName,
                    'zone' => $zone,
                    'listed' => true,
                    'return_code' => $returnCode,
                    'meaning' => $this->interpretReturnCode($listName, $returnCode),
                ];
            }

            return ['list' => $listName, 'zone' => $zone, 'listed' => false];
        } catch (\Throwable $e) {
            Log::debug("DNSBL lookup failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Bulk check multiple IPs against all DNSBLs.
     * Includes throttling to avoid rate limits.
     */
    public function checkMultiple(array $ips): array
    {
        $results = [];

        foreach ($ips as $ip) {
            $listings = $this->checkIp($ip);
            if (! empty($listings)) {
                $results[$ip] = $listings;
            }
        }

        return $results;
    }

    /**
     * Get TXT record (reason) for a DNSBL listing.
     */
    public function getReason(string $ip, string $zone): ?string
    {
        $reversed = $this->reverseIp($ip);
        if ($reversed === null) {
            return null;
        }
        $lookup = "{$reversed}.{$zone}";

        try {
            $txt = @dns_get_record($lookup, DNS_TXT);
            if (! empty($txt)) {
                return $txt[0]['txt'] ?? null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Get all configured DNSBL lists.
     */
    public function getLists(): array
    {
        return $this->lists;
    }

    /**
     * Check if a return code is a false positive (rate limit / error).
     */
    protected function isFalsePositive(string $code): bool
    {
        // Known error/rate-limit codes
        if (in_array($code, $this->falsePositiveCodes)) {
            return true;
        }

        // 127.255.x.x range is generally error responses
        if (str_starts_with($code, '127.255.')) {
            return true;
        }

        return false;
    }

    protected function reverseIp(string $ip): ?string
    {
        // IPv4 → dotted-reverse; IPv6 → ip6.arpa nibble-reverse.
        // Note: most public DNSBLs (Spamhaus ZEN, SpamCop, Barracuda, PSBL, CBL)
        // only publish IPv4 zones. Queries for IPv6 will return NXDOMAIN, which
        // is the correct "not listed" answer.
        return IpHelpers::reverseForDnsbl($ip);
    }

    protected function interpretReturnCode(string $list, string $code): string
    {
        if (str_contains($list, 'spamhaus')) {
            return match ($code) {
                '127.0.0.2' => 'SBL - Spamhaus Block List (spam source)',
                '127.0.0.3' => 'SBL CSS - Spamhaus CSS (spam support)',
                '127.0.0.4' => 'XBL - Exploits/Compromised (via CBL)',
                '127.0.0.5', '127.0.0.6', '127.0.0.7' => 'XBL - Exploits',
                '127.0.0.9' => 'SBL DROP - Hijacked/Leased',
                '127.0.0.10' => 'PBL - Dynamic/Residential (not spam)',
                '127.0.0.11' => 'PBL - ISP Maintained (not spam)',
                default => "Spamhaus listing (code: {$code})",
            };
        }

        if ($list === 'cbl') {
            return 'CBL - Composite Blocking List (compromised/botnet)';
        }

        if ($list === 'psbl') {
            return 'PSBL - Passive Spam Block List';
        }

        if ($list === 'barracuda') {
            return 'Barracuda - Reputation Block List';
        }

        if ($list === 'spamcop') {
            return 'SpamCop - Spam Block List';
        }

        return "Listed (code: {$code})";
    }
}
