<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // High-risk customer (suspended)
        Customer::updateOrCreate(
            ['email' => 'admin@shadyhosting.example'],
            [
                'company' => 'Shady Hosting LLC',
                'external_id' => 'WHMCS-1001',
                'ip_addresses' => ['198.51.100.10', '198.51.100.11', '198.51.100.12'],
                'services' => ['VPS-2001', 'VPS-2002'],
                'abuse_score' => 87.5,
                'is_suspended' => true,
                'suspended_at' => now()->subDays(3),
                'notes' => 'Repeat offender. Multiple spam and phishing cases.',
            ],
        );

        // Medium-risk customer
        Customer::updateOrCreate(
            ['email' => 'info@webdev-agency.example'],
            [
                'company' => 'WebDev Agency',
                'external_id' => 'WHMCS-1002',
                'ip_addresses' => ['198.51.100.20', '198.51.100.21'],
                'services' => ['Dedicated-3001'],
                'abuse_score' => 42.0,
                'notes' => 'One malware incident. Customer was cooperative.',
            ],
        );

        // Clean customers
        Customer::updateOrCreate(
            ['email' => 'support@legit-corp.example'],
            [
                'company' => 'LegitCorp International',
                'external_id' => 'WHMCS-1003',
                'ip_addresses' => ['198.51.100.30', '198.51.100.31', '198.51.100.32', '198.51.100.33'],
                'services' => ['Dedicated-4001', 'Dedicated-4002'],
                'abuse_score' => 5.0,
            ],
        );

        Customer::updateOrCreate(
            ['email' => 'tech@startup-inc.example'],
            [
                'company' => 'Startup Inc.',
                'external_id' => 'WHMCS-1004',
                'ip_addresses' => ['198.51.100.40'],
                'services' => ['VPS-5001'],
                'abuse_score' => 0,
            ],
        );

        // Additional random customers
        Customer::factory()->count(10)->create([
            'abuse_score' => fn () => fake()->randomFloat(2, 0, 30),
        ]);
    }
}
