<?php

namespace App\Console\Commands;

use App\Events\ReportReceived;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\Enrichment\AbuseIpDbService;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAbuseIpDb extends Command
{
    protected $signature = 'abuse:check-abuseipdb
        {--all : Check all active IPs}
        {--stale=24 : Re-check IPs not checked in N hours}
        {--ip= : Check a specific IP}
        {--min-score=0 : Only report IPs with score above this threshold}
        {--create-cases : Auto-create cases for reported IPs}';

    protected $description = 'Check our IP inventory against AbuseIPDB for abuse reports';

    public function handle(AbuseIpDbService $abuseIpDb, ReportNormalizerService $normalizer): int
    {
        if (! $abuseIpDb->isConfigured()) {
            $this->error('AbuseIPDB API key not configured. Set ABUSEIPDB_KEY in .env');
            return self::FAILURE;
        }

        // Single IP check
        if ($ip = $this->option('ip')) {
            $this->checkSingleIp($ip, $abuseIpDb, $normalizer);
            return self::SUCCESS;
        }

        // Build query
        $query = IpAddress::active();

        if (! $this->option('all')) {
            $staleHours = (int) $this->option('stale');
            $query->where(function ($q) use ($staleHours) {
                $q->neverChecked()->orWhere('abuseipdb_checked_at', '<', now()->subHours($staleHours));
            });
        }

        $ips = $query->orderBy('abuseipdb_checked_at', 'asc')->get();

        if ($ips->isEmpty()) {
            $this->info('No IPs to check.');
            return self::SUCCESS;
        }

        $this->info("Checking {$ips->count()} IPs against AbuseIPDB...");
        $bar = $this->output->createProgressBar($ips->count());

        $reported = 0;
        $clean = 0;
        $failed = 0;

        foreach ($ips as $ipRecord) {
            $result = $abuseIpDb->check($ipRecord->ip_address, 90, true);

            if ($result === null) {
                $failed++;
                $bar->advance();
                // Rate limit: AbuseIPDB allows ~60 requests/min on free tier
                usleep(1100000); // 1.1 seconds between requests
                continue;
            }

            $score = $result['abuseConfidenceScore'] ?? 0;
            $totalReports = $result['totalReports'] ?? 0;

            $ipRecord->update([
                'abuseipdb_score' => $score,
                'abuseipdb_reports' => $totalReports,
                'abuseipdb_data' => $result,
                'abuseipdb_checked_at' => now(),
            ]);

            if ($score > 0) {
                $reported++;

                $minScore = (int) $this->option('min-score');
                if ($score >= $minScore && $this->option('create-cases') && $totalReports > 0) {
                    $this->createCaseFromAbuseIpDb($ipRecord, $result, $normalizer);
                }
            } else {
                $clean++;
            }

            $bar->advance();

            // Rate limit
            usleep(1100000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Results: {$reported} reported, {$clean} clean, {$failed} failed");

        if ($reported > 0) {
            $this->warn("Reported IPs:");
            IpAddress::active()
                ->where('abuseipdb_score', '>', 0)
                ->orderByDesc('abuseipdb_score')
                ->each(function ($ip) {
                    $this->line("  {$ip->ip_address} — Score: {$ip->abuseipdb_score}%, Reports: {$ip->abuseipdb_reports} ({$ip->server_name})");
                });
        }

        return self::SUCCESS;
    }

    protected function checkSingleIp(string $ip, AbuseIpDbService $abuseIpDb, ReportNormalizerService $normalizer): void
    {
        $this->info("Checking {$ip}...");

        $result = $abuseIpDb->check($ip, 90, true);

        if ($result === null) {
            $this->error('Failed to check IP. Verify API key and try again.');
            return;
        }

        $score = $result['abuseConfidenceScore'] ?? 0;
        $totalReports = $result['totalReports'] ?? 0;

        // Update IP record if it exists in our inventory
        $ipRecord = IpAddress::findByIp($ip);
        if ($ipRecord) {
            $ipRecord->update([
                'abuseipdb_score' => $score,
                'abuseipdb_reports' => $totalReports,
                'abuseipdb_data' => $result,
                'abuseipdb_checked_at' => now(),
            ]);
        }

        $this->info("IP: {$ip}");
        $this->info("Abuse Confidence Score: {$score}%");
        $this->info("Total Reports: {$totalReports}");
        $this->info("Country: " . ($result['countryCode'] ?? 'N/A'));
        $this->info("ISP: " . ($result['isp'] ?? 'N/A'));
        $this->info("Usage Type: " . ($result['usageType'] ?? 'N/A'));
        $this->info("Domain: " . ($result['domain'] ?? 'N/A'));
        $this->info("Last Reported: " . ($result['lastReportedAt'] ?? 'Never'));
        $this->info("In our inventory: " . ($ipRecord ? 'Yes' : 'No'));

        if ($score > 0 && $this->option('create-cases') && $ipRecord) {
            $this->createCaseFromAbuseIpDb($ipRecord, $result, $normalizer);
            $this->info("Case created for {$ip}.");
        }
    }

    protected function createCaseFromAbuseIpDb(IpAddress $ipRecord, array $data, ReportNormalizerService $normalizer): void
    {
        $reporter = Reporter::firstOrCreate(
            ['email' => 'abuseipdb@feeds.abuseai'],
            ['name' => 'AbuseIPDB', 'type' => 'feed', 'is_trusted' => true],
        );

        $categories = $data['reports'][0]['categories'] ?? [];
        $abuseType = $this->mapCategories($categories);

        $evidence = "AbuseIPDB Report for {$ipRecord->ip_address}\n";
        $evidence .= "Confidence Score: {$data['abuseConfidenceScore']}%\n";
        $evidence .= "Total Reports: {$data['totalReports']}\n";
        $evidence .= "ISP: " . ($data['isp'] ?? 'N/A') . "\n";
        $evidence .= "Country: " . ($data['countryCode'] ?? 'N/A') . "\n";
        $evidence .= "Last Reported: " . ($data['lastReportedAt'] ?? 'N/A') . "\n";

        if (! empty($data['reports'])) {
            $evidence .= "\nRecent Reports:\n";
            foreach (array_slice($data['reports'], 0, 5) as $report) {
                $evidence .= "  - " . ($report['reportedAt'] ?? '') . " | Categories: " . implode(',', $report['categories'] ?? []) . " | " . ($report['comment'] ?? '') . "\n";
            }
        }

        $report = $normalizer->fromFeed([
            'abuse_type' => $abuseType,
            'target_ip' => $ipRecord->ip_address,
            'evidence' => $evidence,
            'reported_at' => $data['lastReportedAt'] ?? now(),
        ], $reporter);

        ReportReceived::dispatch($report);

        Log::info('AbuseIPDB case created', [
            'ip' => $ipRecord->ip_address,
            'score' => $data['abuseConfidenceScore'],
            'reports' => $data['totalReports'],
        ]);
    }

    protected function mapCategories(array $categories): string
    {
        $map = [
            3 => 'fraud', 4 => 'ddos', 5 => 'ddos',
            9 => 'spam', 10 => 'spam', 11 => 'spam',
            14 => 'phishing', 15 => 'malware', 16 => 'malware',
            18 => 'ddos', 19 => 'phishing', 20 => 'fraud',
        ];

        foreach ($categories as $cat) {
            if (isset($map[$cat])) {
                return $map[$cat];
            }
        }

        return 'other';
    }
}
