<?php

namespace App\Support;

/**
 * IP address utilities that work for both IPv4 and IPv6.
 *
 * Centralizes extraction, canonicalization, DNSBL-style reversal, and
 * CIDR expansion so callers don't reinvent IPv4-only regex or ip2long math.
 */
class IpHelpers
{
    /**
     * Candidate pattern: any run of hex digits, colons, and dots. We don't try
     * to perfectly describe IPv6 in regex — it's notoriously fragile. Instead
     * we grab anything that *could* be an IP and defer to inet_pton for
     * validation, with a small fallback that trims surrounding punctuation.
     */
    // Lookbehind/ahead exclude adjacent letters and digits so labels like
    // "IPv6:…" or "v4=…" don't fuse into the candidate.
    private const IP_CANDIDATE_REGEX = '/(?<![a-zA-Z0-9])[0-9A-Fa-f:.]+(?![a-zA-Z0-9])/';

    /**
     * Extract all unique, canonicalized IPs (v4 or v6) from a block of text.
     * Invalid candidates are discarded.
     *
     * @return string[]
     */
    public static function extractIps(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all(self::IP_CANDIDATE_REGEX, $text, $matches);

        $out = [];
        foreach ($matches[0] ?? [] as $candidate) {
            // Skip trivially-short tokens so single dots, ":", etc. don't churn.
            if (strlen($candidate) < 3) {
                continue;
            }
            $canonical = self::canonicalize($candidate);
            if ($canonical === null) {
                // Try with surrounding punctuation stripped (e.g. "1.2.3.4." from log lines).
                $trimmed = trim($candidate, '.:');
                if ($trimmed !== '' && $trimmed !== $candidate) {
                    $canonical = self::canonicalize($trimmed);
                }
            }
            if ($canonical !== null) {
                $out[$canonical] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Canonical form of an IP address, or null if invalid.
     *   "2001:DB8::1" → "2001:db8::1"
     *   "::ffff:1.2.3.4" → "1.2.3.4"      (IPv4-mapped collapses to IPv4)
     *   "192.168.01.01" → null             (leading zeros rejected)
     */
    public static function canonicalize(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) → collapse to IPv4 form
        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            $zeroMapped = str_repeat("\x00", 10) . "\xff\xff";
            if ($prefix === $zeroMapped) {
                return inet_ntop(substr($packed, 12)) ?: null;
            }
        }

        $canonical = @inet_ntop($packed);
        return $canonical === false ? null : $canonical;
    }

    public static function isValid(string $ip): bool
    {
        return self::canonicalize($ip) !== null;
    }

    public static function isIpv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public static function isIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Reverse an IP into its DNSBL-queryable form, without the zone suffix.
     *   IPv4 "1.2.3.4"    → "4.3.2.1"                   (query under in-addr.arpa or a DNSBL zone)
     *   IPv6 "2001:db8::1" → "1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2"
     */
    public static function reverseForDnsbl(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            return implode('.', array_reverse(explode('.', inet_ntop($packed))));
        }

        // IPv6: one hex nibble per label, reversed
        $hex = bin2hex($packed); // 32 chars
        return implode('.', array_reverse(str_split($hex, 1)));
    }

    /**
     * Return the DNS zone (suffix) for the IP family.
     */
    public static function arpaZone(string $ip): ?string
    {
        if (self::isIpv4($ip)) {
            return 'in-addr.arpa';
        }
        if (self::isIpv6($ip)) {
            return 'ip6.arpa';
        }
        return null;
    }

    /**
     * Does an address fall inside a CIDR block? Returns false on family
     * mismatch (IPv4 against an IPv6 block, etc.) or on invalid input.
     */
    public static function ipInCidr(string $ip, string $network, int $prefixLength): bool
    {
        $ipPacked = @inet_pton($ip);
        $netPacked = @inet_pton($network);
        if ($ipPacked === false || $netPacked === false) {
            return false;
        }
        if (strlen($ipPacked) !== strlen($netPacked)) {
            return false;
        }
        $bits = strlen($ipPacked) * 8;
        if ($prefixLength < 0 || $prefixLength > $bits) {
            return false;
        }
        if ($prefixLength === 0) {
            return true;
        }
        return self::applyMask($ipPacked, $prefixLength) === self::applyMask($netPacked, $prefixLength);
    }

    /** True when prefix_length represents a full host for the given IP family. */
    public static function isHostPrefix(string $ip, int $prefixLength): bool
    {
        if (self::isIpv4($ip)) {
            return $prefixLength === 32;
        }
        if (self::isIpv6($ip)) {
            return $prefixLength === 128;
        }
        return false;
    }

    /** Full-host prefix length for the given IP family: 32 for v4, 128 for v6. */
    public static function hostPrefixFor(string $ip): ?int
    {
        if (self::isIpv4($ip)) {
            return 32;
        }
        if (self::isIpv6($ip)) {
            return 128;
        }
        return null;
    }

    /**
     * Expand a CIDR block into individual host IPs, canonicalized.
     * Works for both IPv4 and IPv6. Returns an empty array on invalid input
     * or if the block would exceed $maxHosts (safety rail — a /32 IPv6 is
     * 2^96 hosts and will OOM the process).
     *
     * @return string[]
     */
    public static function expandCidr(string $cidr, int $maxHosts = 65536): array
    {
        if (! str_contains($cidr, '/')) {
            return [];
        }

        [$base, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        $packed = @inet_pton($base);
        if ($packed === false) {
            return [];
        }

        $bits = strlen($packed) === 4 ? 32 : 128;
        if ($prefix < 0 || $prefix > $bits) {
            return [];
        }

        $hostBits = $bits - $prefix;

        // Safety rail: anything over ~65k hosts is too large to materialize.
        if ($hostBits > 30 || (1 << min($hostBits, 30)) > $maxHosts) {
            return [];
        }

        $hostCount = 1 << $hostBits;
        if ($hostCount > $maxHosts) {
            return [];
        }

        // Mask base down to the network address
        $network = self::applyMask($packed, $prefix);

        $out = [];
        for ($i = 0; $i < $hostCount; $i++) {
            $addr = self::addToPacked($network, $i);
            $canonical = inet_ntop($addr);
            if ($canonical !== false) {
                $out[] = $canonical;
            }
        }
        return $out;
    }

    /**
     * Expand an inclusive dash range into individual host IPs, canonicalized.
     * IPv4 only for now — IPv6 dash-ranges are exotic and error-prone; callers
     * get an empty array and should surface a clear error to the user.
     *
     * @return string[]
     */
    public static function expandDashRange(string $range, int $maxHosts = 65536): array
    {
        if (! str_contains($range, '-')) {
            return [];
        }

        [$start, $end] = array_map('trim', explode('-', $range, 2));
        if (! self::isIpv4($start) || ! self::isIpv4($end)) {
            return [];
        }

        $s = ip2long($start);
        $e = ip2long($end);
        if ($s === false || $e === false || $e < $s) {
            return [];
        }
        if (($e - $s + 1) > $maxHosts) {
            return [];
        }

        $out = [];
        for ($i = $s; $i <= $e; $i++) {
            $out[] = long2ip($i);
        }
        return $out;
    }

    /** Apply a prefix-length mask to a packed binary IP. */
    protected static function applyMask(string $packed, int $prefix): string
    {
        $bytes = str_split($packed);
        $result = '';
        for ($i = 0; $i < count($bytes); $i++) {
            $byteStart = $i * 8;
            if ($prefix >= $byteStart + 8) {
                $result .= $bytes[$i];
            } elseif ($prefix <= $byteStart) {
                $result .= "\x00";
            } else {
                $bitsInByte = $prefix - $byteStart;
                $mask = (0xff << (8 - $bitsInByte)) & 0xff;
                $result .= chr(ord($bytes[$i]) & $mask);
            }
        }
        return $result;
    }

    /** Add an integer offset to a packed binary IP. */
    protected static function addToPacked(string $packed, int $offset): string
    {
        $bytes = array_map('ord', str_split($packed));
        $i = count($bytes) - 1;
        $carry = $offset;
        while ($i >= 0 && $carry > 0) {
            $sum = $bytes[$i] + ($carry & 0xff);
            $bytes[$i] = $sum & 0xff;
            $carry = ($carry >> 8) + ($sum >> 8);
            $i--;
        }
        return implode('', array_map('chr', $bytes));
    }
}
