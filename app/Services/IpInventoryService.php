<?php

namespace App\Services;

use App\Models\IpAddress;
use App\Support\IpHelpers;
use Illuminate\Support\Facades\DB;

class IpInventoryService
{
    /**
     * Threshold below which CIDR imports get expanded to per-host rows rather
     * than stored as a single block. IPv4 /20 (~4k hosts) is the widest we
     * expand by default; anything wider — or any IPv6 CIDR — is stored as a
     * block row so a /64 doesn't try to materialize 18 quintillion rows.
     */
    protected const EXPAND_MAX_HOSTS = 4096;

    /**
     * Add a single IP to the inventory (stored as a /32 or /128 host row).
     * If the row already exists, it's updated: supplied fields overwrite the
     * previous value, blanks are preserved, and tags are merged (union) with
     * the existing set rather than replaced.
     */
    public function addSingle(
        string $ip,
        ?string $serverName = null,
        ?string $customerId = null,
        ?array $tags = null,
        ?string $notes = null,
    ): IpAddress {
        $canonical = IpHelpers::canonicalize($ip) ?? $ip;
        $hostWidth = IpHelpers::hostPrefixFor($canonical);

        return $this->upsertIp(
            ['ip_address' => $canonical, 'prefix_length' => $hostWidth],
            [
                'server_name' => $serverName,
                'customer_id' => $customerId,
                'tags' => $tags,
                'notes' => $notes,
            ],
        );
    }

    /**
     * Preserve-fields, merge-tags upsert used by every add* path. Fields with a
     * null value in $fields are left alone on update; tags are unioned with
     * the existing array; status resets to 'active' so re-adding a
     * decommissioned IP reactivates it.
     *
     * @param  array<string,mixed>  $lookup
     * @param  array<string,mixed>  $fields
     */
    protected function upsertIp(array $lookup, array $fields): IpAddress
    {
        /** @var IpAddress $record */
        $record = IpAddress::firstOrNew($lookup);

        $newTags = $fields['tags'] ?? null;
        unset($fields['tags']);

        // Only overwrite fields the caller supplied; preserve existing otherwise.
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $record->{$key} = $value;
        }

        // Tags: union with existing rather than replace.
        if (is_array($newTags) && ! empty($newTags)) {
            $existing = $record->tags ?? [];
            $record->tags = array_values(array_unique(array_merge($existing, $newTags)));
        }

        // Re-adding an IP that was decommissioned should flip it back to active;
        // new records default to active too.
        $record->status = 'active';

        $record->save();
        return $record;
    }

    /**
     * Store a CIDR block as a single inventory row rather than expanding it.
     * Membership queries go through IpAddress::isOurs / findByIp.
     */
    public function addBlock(
        string $cidr,
        ?string $serverName = null,
        ?string $customerId = null,
        ?array $tags = null,
        ?string $notes = null,
    ): ?IpAddress {
        [$base, $prefixPart] = array_pad(explode('/', $cidr, 2), 2, null);
        $canonical = IpHelpers::canonicalize((string) $base);
        $prefix = (int) $prefixPart;
        $hostWidth = $canonical ? IpHelpers::hostPrefixFor($canonical) : null;

        if ($canonical === null || $hostWidth === null || $prefix < 0 || $prefix > $hostWidth) {
            return null;
        }

        return $this->upsertIp(
            ['ip_address' => $canonical, 'prefix_length' => $prefix],
            [
                'server_name' => $serverName,
                'customer_id' => $customerId,
                'range_source' => $cidr,
                'tags' => $tags,
                'notes' => $notes,
            ],
        );
    }

    /**
     * Expand a CIDR range and import all IPs individually.
     * Returns count of IPs imported.
     */
    public function addRange(
        string $cidr,
        ?string $serverName = null,
        ?string $customerId = null,
        ?array $tags = null,
        ?string $notes = null,
    ): int {
        $ips = $this->expandCidr($cidr);

        if (empty($ips)) {
            return 0;
        }

        $count = 0;

        // Batch insert in chunks to avoid memory issues on large ranges
        foreach (array_chunk($ips, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $cidr, $serverName, $customerId, $tags, $notes, &$count) {
                foreach ($chunk as $ip) {
                    $hostWidth = IpHelpers::hostPrefixFor($ip);
                    $this->upsertIp(
                        ['ip_address' => $ip, 'prefix_length' => $hostWidth],
                        [
                            'server_name' => $serverName,
                            'customer_id' => $customerId,
                            'range_source' => $cidr,
                            'tags' => $tags,
                            'notes' => $notes,
                        ],
                    );
                    $count++;
                }
            });
        }

        return $count;
    }

    /**
     * Add a dash-range like "192.168.1.1-192.168.1.50".
     */
    public function addDashRange(
        string $range,
        ?string $serverName = null,
        ?string $customerId = null,
        ?array $tags = null,
        ?string $notes = null,
    ): int {
        $ips = $this->expandDashRange($range);

        if (empty($ips)) {
            return 0;
        }

        $count = 0;

        foreach (array_chunk($ips, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $range, $serverName, $customerId, $tags, $notes, &$count) {
                foreach ($chunk as $ip) {
                    $hostWidth = IpHelpers::hostPrefixFor($ip);
                    $this->upsertIp(
                        ['ip_address' => $ip, 'prefix_length' => $hostWidth],
                        [
                            'server_name' => $serverName,
                            'customer_id' => $customerId,
                            'range_source' => $range,
                            'tags' => $tags,
                            'notes' => $notes,
                        ],
                    );
                    $count++;
                }
            });
        }

        return $count;
    }

    /**
     * Smart import — detect input format and route to correct method.
     * Supports: single IP, CIDR (expands if small, stored as block if wide),
     * and IPv4 dash range (x.x.x.x-x.x.x.x).
     */
    public function import(
        string $input,
        ?string $serverName = null,
        ?string $customerId = null,
        ?array $tags = null,
        ?string $notes = null,
    ): array {
        $input = trim($input);

        // CIDR notation
        if (str_contains($input, '/')) {
            [$base, $prefixPart] = array_pad(explode('/', $input, 2), 2, null);
            $canonical = $base ? IpHelpers::canonicalize($base) : null;
            $prefix = (int) $prefixPart;
            $hostWidth = $canonical ? IpHelpers::hostPrefixFor($canonical) : null;

            if ($canonical === null || $hostWidth === null || $prefix < 0 || $prefix > $hostWidth) {
                return ['type' => 'invalid', 'input' => $input, 'count' => 0];
            }

            $hostBits = $hostWidth - $prefix;
            $expand = IpHelpers::isIpv4($canonical)
                && $hostBits >= 0
                && $hostBits <= 30
                && (1 << $hostBits) <= self::EXPAND_MAX_HOSTS;

            if ($expand) {
                $count = $this->addRange($input, $serverName, $customerId, $tags, $notes);
                if ($count > 0) {
                    return ['type' => 'cidr_expanded', 'input' => $input, 'count' => $count];
                }
            }

            // Too wide to expand — store the block as a single entry.
            if ($this->addBlock($input, $serverName, $customerId, $tags, $notes)) {
                return ['type' => 'block', 'input' => "{$canonical}/{$prefix}", 'count' => 1];
            }

            return ['type' => 'invalid', 'input' => $input, 'count' => 0];
        }

        // Dash range (IPv4 only)
        if (str_contains($input, '-')) {
            $count = $this->addDashRange($input, $serverName, $customerId, $tags, $notes);
            return ['type' => 'range', 'input' => $input, 'count' => $count];
        }

        // Single IP (IPv4 or IPv6)
        $canonical = IpHelpers::canonicalize($input);
        if ($canonical !== null) {
            $this->addSingle($canonical, $serverName, $customerId, $tags, $notes);
            return ['type' => 'single', 'input' => $canonical, 'count' => 1];
        }

        return ['type' => 'invalid', 'input' => $input, 'count' => 0];
    }

    /**
     * Bulk import from multiline text (one entry per line).
     */
    public function bulkImport(
        string $text,
        ?string $serverName = null,
        ?string $customerId = null,
        ?array $tags = null,
    ): array {
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $results = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'details' => []];

        foreach ($lines as $line) {
            // Support "ip,server_name" CSV format
            $parts = str_getcsv($line);
            $ipInput = trim($parts[0] ?? '');
            $lineServerName = trim($parts[1] ?? '') ?: $serverName;

            if (empty($ipInput) || str_starts_with($ipInput, '#')) {
                continue; // skip empty lines and comments
            }

            $result = $this->import($ipInput, $lineServerName, $customerId, $tags);
            $results['total']++;
            $results['imported'] += $result['count'];
            if ($result['type'] === 'invalid') {
                $results['skipped']++;
            }
            $results['details'][] = $result;
        }

        return $results;
    }

    /**
     * Check if an IP belongs to our network.
     */
    public function isOurIp(string $ip): bool
    {
        return IpAddress::isOurs($ip);
    }

    /**
     * Look up an IP and return its record if it's ours.
     */
    public function lookup(string $ip): ?IpAddress
    {
        return IpAddress::findByIp($ip);
    }

    /**
     * Expand a CIDR notation (IPv4 or IPv6) to individual IPs.
     * Safety: max 65536 IPs to prevent memory issues — IPv6 requires a
     * narrow prefix (e.g. /112 or tighter).
     */
    public function expandCidr(string $cidr): array
    {
        $ips = IpHelpers::expandCidr($cidr, maxHosts: 65536);

        if (empty($ips)) {
            return [];
        }

        // For a traditional IPv4 subnet (/24 or wider), drop the network and
        // broadcast addresses. IpHelpers returns the raw host list so we decide
        // that policy here.
        [$base, $prefixPart] = array_pad(explode('/', $cidr, 2), 2, null);
        $prefix = (int) $prefixPart;
        $isIpv4 = IpHelpers::isIpv4($base ?? '');
        if ($isIpv4 && $prefix >= 0 && $prefix <= 30 && count($ips) >= 4) {
            array_shift($ips);  // network
            array_pop($ips);    // broadcast
        }

        return $ips;
    }

    /**
     * Expand a dash range like "192.168.1.1-192.168.1.50".
     * IPv4 only — IPv6 dash-ranges are unusual; users should use CIDR instead.
     */
    public function expandDashRange(string $range): array
    {
        return IpHelpers::expandDashRange($range, maxHosts: 65536);
    }
}
