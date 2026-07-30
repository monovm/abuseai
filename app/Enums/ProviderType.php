<?php

namespace App\Enums;

/**
 * Which infrastructure integration a brand uses for IP → service lookups
 * and operator actions (suspend, etc.).
 *
 * Each value maps to exactly one provider class via ProviderRegistry.
 * Adding a new integration means adding a case here, registering it in
 * ProviderRegistry, and wiring its config field group into the brand
 * admin form — nothing in the case engine or Livewire panel should
 * branch on the value.
 */
enum ProviderType: string
{
    case None = 'none';
    case Virtualizor = 'virtualizor';
    case Whmcs = 'whmcs';
    case WhmcsVirtualizor = 'whmcs_virtualizor';
    case SolusVm = 'solusvm';
    case Proxmox = 'proxmox';
    case Blesta = 'blesta';
    case Hostbill = 'hostbill';
    case GenericHttp = 'generic_http';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No integration',
            self::Virtualizor => 'Virtualizor only',
            self::Whmcs => 'WHMCS only',
            self::WhmcsVirtualizor => 'WHMCS + Virtualizor',
            self::SolusVm => 'SolusVM',
            self::Proxmox => 'Proxmox VE',
            self::Blesta => 'Blesta',
            self::Hostbill => 'HostBill',
            self::GenericHttp => 'Custom HTTP API',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'No automatic lookups; agents triage manually.',
            self::Virtualizor => 'IP → VPS lookup against Virtualizor. No billing data.',
            self::Whmcs => 'IP → service lookup against WHMCS. No VPS panel data.',
            self::WhmcsVirtualizor => 'IP → Virtualizor → hostname → WHMCS service. Most complete view.',
            self::SolusVm => 'IP → VPS lookup against SolusVM v1 (vserver-checkexists + vserver-info).',
            self::Proxmox => 'IP → VM scan against Proxmox VE cluster. Best-effort match against net config and description fields.',
            self::Blesta => 'IP → service lookup against Blesta billing.',
            self::Hostbill => 'IP → account lookup against HostBill billing.',
            self::GenericHttp => 'Map any external API via URL templates and JSONPath.',
        };
    }
}
