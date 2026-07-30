<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Services\IpInventoryService;
use Illuminate\Database\Seeder;

/**
 * Seeds a small inventory of demo IPs against the demo customers from
 * CustomerSeeder. All ranges below come from RFC 5737 (TEST-NET-2,
 * 198.51.100.0/24) so this seed never collides with real allocations.
 */
class IpAddressSeeder extends Seeder
{
    public function run(): void
    {
        $service = new IpInventoryService();

        // Seed IPs matching the customers above
        $shadyHosting = Customer::where('email', 'admin@shadyhosting.example')->first();
        $webdevAgency = Customer::where('email', 'info@webdev-agency.example')->first();
        $legitCorp = Customer::where('email', 'support@legit-corp.example')->first();
        $startup = Customer::where('email', 'tech@startup-inc.example')->first();

        // Shady Hosting IPs
        foreach (['198.51.100.10', '198.51.100.11', '198.51.100.12'] as $ip) {
            $service->addSingle($ip, 'shady-vps', $shadyHosting?->id, ['datacenter-eu', 'rack-A1']);
        }

        // WebDev Agency IPs
        foreach (['198.51.100.20', '198.51.100.21'] as $ip) {
            $service->addSingle($ip, 'webdev-dedicated', $webdevAgency?->id, ['datacenter-eu', 'rack-B2']);
        }

        // LegitCorp IPs
        foreach (['198.51.100.30', '198.51.100.31', '198.51.100.32', '198.51.100.33'] as $ip) {
            $service->addSingle($ip, 'legit-dedicated', $legitCorp?->id, ['datacenter-us', 'rack-C1']);
        }

        // Startup IP
        $service->addSingle('198.51.100.40', 'startup-vps', $startup?->id, ['datacenter-eu', 'rack-A3']);

        // A /28 subnet for shared hosting (14 IPs, unassigned to specific customer)
        $service->addRange('198.51.100.64/28', 'shared-hosting', null, ['datacenter-eu', 'shared']);

        // A small range for mail servers
        $service->addDashRange('198.51.100.80-198.51.100.84', 'mail-srv', null, ['datacenter-us', 'mail']);

        // Infrastructure IPs (DNS, monitoring, etc.)
        $service->addSingle('198.51.100.1', 'ns1.example.com', null, ['infrastructure', 'dns']);
        $service->addSingle('198.51.100.2', 'ns2.example.com', null, ['infrastructure', 'dns']);
        $service->addSingle('198.51.100.3', 'monitor.example.com', null, ['infrastructure', 'monitoring']);

        // A decommissioned range
        $service->addRange('198.51.100.240/29', 'old-subnet', null, ['decommissioned']);
        \App\Models\IpAddress::where('range_source', '198.51.100.240/29')
            ->update(['status' => 'decommissioned']);
    }
}
