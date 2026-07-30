<?php

namespace App\Enums;

/**
 * How the WHMCS + Virtualizor combined provider pivots from a Virtualizor
 * VPS row to a WHMCS service. Different operators store the join key in
 * different columns — some put the hostname in WHMCS's Domain field,
 * others rely on the dedicated-IP column, others store the bare IP as
 * the domain. This enum makes the choice explicit per brand instead of
 * us guessing.
 *
 * Naming convention: {source field on Virtualizor}_to_{target field on WHMCS}.
 */
enum WhmcsMatchStrategy: string
{
    case HostnameToDomain = 'hostname_to_domain';
    case IpToDomain = 'ip_to_domain';
    case HostnameToDedicatedIp = 'hostname_to_dedicatedip';
    case IpToDedicatedIp = 'ip_to_dedicatedip';

    public static function default(): self
    {
        return self::HostnameToDomain;
    }

    public function label(): string
    {
        return match ($this) {
            self::HostnameToDomain => 'VPS hostname → WHMCS domain',
            self::IpToDomain => 'IP → WHMCS domain',
            self::HostnameToDedicatedIp => 'VPS hostname → WHMCS dedicated IP',
            self::IpToDedicatedIp => 'IP → WHMCS dedicated IP',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HostnameToDomain => "Looks up WHMCS via GetClientsProducts(domain={vps.hostname}). Fast — one API call. Use when service rows store the VPS hostname in the Domain column.",
            self::IpToDomain => "Looks up WHMCS via GetClientsProducts(domain={ip}). Fast — one API call. Use when operators paste the bare IP into the Domain column.",
            self::HostnameToDedicatedIp => "Scans WHMCS services and filters on dedicatedip == hostname. Slow — paginated scan. Rarely useful; included for completeness.",
            self::IpToDedicatedIp => "Scans WHMCS services and filters on dedicatedip == IP. Slow — paginated scan. Use when WHMCS reliably populates the Dedicated IP column and Domain is empty.",
        };
    }

    /** Which Virtualizor field feeds the lookup ('hostname' or 'ip'). */
    public function vzSource(): string
    {
        return match ($this) {
            self::HostnameToDomain, self::HostnameToDedicatedIp => 'hostname',
            self::IpToDomain, self::IpToDedicatedIp => 'ip',
        };
    }

    /** Which WHMCS column we filter on ('domain' or 'dedicatedip'). */
    public function whmcsTarget(): string
    {
        return match ($this) {
            self::HostnameToDomain, self::IpToDomain => 'domain',
            self::HostnameToDedicatedIp, self::IpToDedicatedIp => 'dedicatedip',
        };
    }

    /**
     * True for strategies that require an O(N) scan of every service in
     * WHMCS rather than a single targeted query. UI should warn admins
     * about the API cost before they pick one of these.
     */
    public function requiresScan(): bool
    {
        return $this->whmcsTarget() === 'dedicatedip';
    }
}
