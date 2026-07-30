<?php

namespace App\Console\Commands;

use App\Models\IpAddress;
use App\Services\IpReputationService;
use Illuminate\Console\Command;

class ScanIpReputation extends Command
{
    protected $signature = 'abuse:scan-reputation
        {--all : Scan all active IPs}
        {--stale=24 : Only re-scan IPs not scanned in N hours}
        {--ip= : Scan a specific IP}
        {--threshold= : Override case creation threshold}
        {--create-cases : Auto-create cases for IPs below threshold}
        {--dry-run : Show results without updating}
        {--limit=0 : Max IPs to scan (0=unlimited)}';

    protected $description = 'Scan IP inventory reputation using all collectors (AbuseIPDB, DNSBL, VirusTotal, Shodan)';

    public function handle(IpReputationService $reputation): int
    {
        // Single IP
        if ($ip = $this->option('ip')) {
            return $this->scanSingle($ip, $reputation);
        }

        // Build query
        $query = IpAddress::active();

        if (! $this->option('all')) {
            $staleHours = (int) $this->option('stale');
            $query->where(function ($q) use ($staleHours) {
                $q->whereNull('reputation_updated_at')
                  ->orWhere('reputation_updated_at', '<', now()->subHours($staleHours));
            });
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $ips = $query->get();

        if ($ips->isEmpty()) {
            $this->info('No IPs to scan.');
            return self::SUCCESS;
        }

        $this->info("Scanning {$ips->count()} IPs...");
        $bar = $this->output->createProgressBar($ips->count());

        $stats = ['good' => 0, 'fair' => 0, 'poor' => 0, 'critical' => 0, 'cases' => 0, 'errors' => 0];

        foreach ($ips as $ipRecord) {
            try {
                if ($this->option('dry-run')) {
                    $bar->advance();
                    continue;
                }

                if ($this->option('create-cases')) {
                    $result = $reputation->collectAndEvaluate($ipRecord);
                    if ($result['case_created'] ?? false) {
                        $stats['cases']++;
                        $this->newLine();
                        $this->warn("  CASE: {$ipRecord->ip_address} — score {$result['score']}/100 → {$result['case_number']}");
                    }
                } else {
                    $result = $reputation->collect($ipRecord);
                }

                $score = $result['score'];
                if ($score >= 80) $stats['good']++;
                elseif ($score >= 50) $stats['fair']++;
                elseif ($score >= 30) $stats['poor']++;
                else $stats['critical']++;

                if ($score < 50) {
                    $this->newLine();
                    $this->warn("  {$ipRecord->ip_address} ({$ipRecord->server_name}) — Score: {$score}/100");
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->newLine();
                $this->error("  {$ipRecord->ip_address}: {$e->getMessage()}");
            }

            $bar->advance();

            // Rate limit: 1.5 seconds between IPs (AbuseIPDB limit)
            usleep(1500000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("=== Reputation Scan Results ===");
        $this->info("Good (80-100):     {$stats['good']}");
        $this->info("Fair (50-79):      {$stats['fair']}");
        $this->warn("Poor (30-49):      {$stats['poor']}");
        $this->error("Critical (<30):    {$stats['critical']}");
        if ($stats['cases'] > 0) {
            $this->error("Cases created:     {$stats['cases']}");
        }
        if ($stats['errors'] > 0) {
            $this->error("Errors:            {$stats['errors']}");
        }

        return self::SUCCESS;
    }

    protected function scanSingle(string $ip, IpReputationService $reputation): int
    {
        $ipRecord = IpAddress::findByIp($ip);

        if (! $ipRecord) {
            $this->error("IP {$ip} not found in inventory.");
            return self::FAILURE;
        }

        $this->info("Scanning {$ip} ({$ipRecord->server_name})...");

        $result = $this->option('create-cases')
            ? $reputation->collectAndEvaluate($ipRecord)
            : $reputation->collect($ipRecord);

        $this->newLine();
        $this->info("IP: {$ip}");
        $this->info("Server: " . ($ipRecord->server_name ?? 'Unknown'));
        $this->info("Reputation: {$result['score']}/100 ({$result['label']})");
        $this->newLine();

        foreach ($result['collectors'] as $name => $data) {
            if (isset($data['error'])) {
                $this->line("  [{$name}] Error: {$data['error']}");
                continue;
            }
            if (($data['available'] ?? true) === false) {
                $this->line("  [{$name}] Not configured");
                continue;
            }

            $penalty = $data['penalty'] ?? 0;
            $detail = match ($name) {
                'abuseipdb' => "Score: " . ($data['score'] ?? 0) . "%, Reports: " . ($data['reports'] ?? 0),
                'dnsbl' => "Listed: " . ($data['actionable_count'] ?? 0) . " blacklists" . (($data['informational_count'] ?? 0) > 0 ? " + " . $data['informational_count'] . " informational" : ''),
                'virustotal' => "Malicious: " . ($data['malicious'] ?? 0) . ", Suspicious: " . ($data['suspicious'] ?? 0),
                'snds' => ($data['found'] ?? false) ? "Filter: " . ($data['filter_result'] ?? '?') . ", Traps: " . ($data['trap_hits'] ?? 0) . ($data['blocked'] ? ' BLOCKED' : '') : 'Not in SNDS data',
                'shodan' => "Vulns: " . ($data['vuln_count'] ?? 0) . ", Ports: " . count($data['ports'] ?? []),
                'open_cases' => "Open: " . ($data['count'] ?? 0),
                default => json_encode($data),
            };

            $prefix = $penalty > 0 ? '-' . $penalty . ' pts' : 'clean';
            $this->line("  [{$name}] {$detail} → {$prefix}");
        }

        if ($result['case_created'] ?? false) {
            $this->newLine();
            $this->warn("Case created: {$result['case_number']}");
        }

        return self::SUCCESS;
    }
}
