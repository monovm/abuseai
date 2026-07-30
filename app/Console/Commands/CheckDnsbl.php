<?php

namespace App\Console\Commands;

use App\Events\ReportReceived;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\Ingestion\Feeds\DnsblService;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Console\Command;

class CheckDnsbl extends Command
{
    protected $signature = 'abuse:check-dnsbl
        {--all : Check all active IPs}
        {--ip= : Check a specific IP}
        {--list= : Check against a specific DNSBL only (cbl, psbl, spamhaus_zen, etc.)}
        {--create-cases : Auto-create cases for listed IPs}';

    protected $description = 'Check IP inventory against DNSBL blacklists (CBL, PSBL, Spamhaus, etc.)';

    public function handle(DnsblService $dnsbl, ReportNormalizerService $normalizer): int
    {
        // Single IP check
        if ($ip = $this->option('ip')) {
            $this->checkSingleIp($ip, $dnsbl, $normalizer);
            return self::SUCCESS;
        }

        $query = IpAddress::active();
        $ips = $query->pluck('ip_address')->toArray();

        if (empty($ips)) {
            $this->info('No active IPs in inventory.');
            return self::SUCCESS;
        }

        $this->info("Checking " . count($ips) . " IPs against DNSBLs...");
        $bar = $this->output->createProgressBar(count($ips));

        $listed = 0;
        $clean = 0;

        foreach ($ips as $ip) {
            $specificList = $this->option('list');

            $results = $specificList
                ? array_filter([($dnsbl->checkIpAgainst($ip, $specificList))])
                : $dnsbl->checkIp($ip);

            $listings = array_filter($results, fn ($r) => $r['listed'] ?? false);

            // Filter out PBL listings (dynamic/residential — informational, not abuse)
            $actionableListings = array_filter($listings, fn ($r) =>
                ! str_contains(strtolower($r['meaning'] ?? ''), 'pbl')
                && ! str_contains(strtolower($r['meaning'] ?? ''), 'dynamic')
                && ! str_contains(strtolower($r['meaning'] ?? ''), 'residential')
            );

            if (! empty($listings)) {
                $listed++;
                $listNames = array_map(fn ($r) => $r['list'], $listings);
                $this->newLine();
                $this->warn("  {$ip} — LISTED on: " . implode(', ', $listNames));

                // Only create cases for actionable listings (not PBL)
                if ($this->option('create-cases') && ! empty($actionableListings)) {
                    $this->createCase($ip, $actionableListings, $normalizer);
                }
            } else {
                $clean++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Results: {$listed} listed, {$clean} clean");

        return self::SUCCESS;
    }

    protected function checkSingleIp(string $ip, DnsblService $dnsbl, ReportNormalizerService $normalizer): void
    {
        $this->info("Checking {$ip} against all DNSBLs...");

        $results = $dnsbl->checkIp($ip);
        $listings = array_filter($results, fn ($r) => $r['listed'] ?? false);

        if (empty($listings)) {
            $this->info("{$ip} is CLEAN — not listed on any DNSBL.");
            return;
        }

        $this->warn("{$ip} is LISTED on " . count($listings) . " DNSBL(s):");

        foreach ($listings as $listing) {
            $this->line("  [{$listing['list']}] {$listing['zone']} — {$listing['meaning']}");

            $reason = $dnsbl->getReason($ip, $listing['zone']);
            if ($reason) {
                $this->line("    Reason: {$reason}");
            }
        }

        if ($this->option('create-cases')) {
            $this->createCase($ip, $listings, $normalizer);
            $this->info("Case created for {$ip}.");
        }
    }

    protected function createCase(string $ip, array $listings, ReportNormalizerService $normalizer): void
    {
        $reporter = Reporter::firstOrCreate(
            ['email' => 'dnsbl@feeds.abuseai'],
            ['name' => 'DNSBL Check', 'type' => 'feed', 'is_trusted' => true],
        );

        $listNames = array_map(fn ($r) => $r['list'], $listings);
        $evidence = "IP {$ip} found on DNSBL blacklist(s):\n\n";

        foreach ($listings as $listing) {
            $evidence .= "- [{$listing['list']}] {$listing['zone']}: {$listing['meaning']}\n";
        }

        $report = $normalizer->fromFeed([
            'abuse_type' => 'spam',
            'target_ip' => $ip,
            'evidence' => $evidence,
            'reported_at' => now(),
        ], $reporter);

        ReportReceived::dispatch($report);
    }
}
