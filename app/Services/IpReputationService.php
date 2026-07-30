<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use App\Models\IpAddress;
use App\Models\Reporter;
use App\Services\CaseEngine\CaseCreatorService;
use App\Services\Enrichment\AbuseIpDbService;
use App\Services\Enrichment\ShodanService;
use App\Services\Enrichment\SndsService;
use App\Services\Enrichment\VirusTotalService;
use App\Services\Ingestion\Feeds\DnsblService;
use App\Services\Ingestion\ReportNormalizerService;
use Illuminate\Support\Facades\Log;

/**
 * IP Reputation System.
 *
 * Collects data from multiple sources (AbuseIPDB, DNSBL, VirusTotal, Shodan, open cases),
 * calculates a reputation score (100=clean, 0=worst), and auto-creates cases when
 * reputation drops below threshold.
 *
 * Cases are ONLY created from reputation when score < threshold (default 30).
 * Automated scans never directly create cases — they only update reputation.
 */
class IpReputationService
{
    protected float $caseThreshold;
    protected float $recoveryThreshold;
    protected array $weights;

    protected ?array $sndsCache = null;

    public function __construct(
        protected AbuseIpDbService $abuseIpDb,
        protected DnsblService $dnsbl,
        protected VirusTotalService $virusTotal,
        protected ShodanService $shodan,
        protected SndsService $snds,
    ) {
        $this->caseThreshold = (float) config('abusedesk.reputation.case_threshold', 30);
        $this->recoveryThreshold = (float) config('abusedesk.reputation.recovery_threshold', 50);
        $this->weights = config('abusedesk.reputation.weights', []);
    }

    /**
     * Run all collectors for an IP and calculate reputation.
     * This is the main entry point for automated scanning.
     */
    public function collect(IpAddress $ip): array
    {
        $collectors = [];

        // Collector 1: AbuseIPDB
        $collectors['abuseipdb'] = $this->collectAbuseIpDb($ip);

        // Collector 2: DNSBL (CBL, PSBL, Spamhaus ZEN, Barracuda, SpamCop)
        $collectors['dnsbl'] = $this->collectDnsbl($ip);

        // Collector 3: VirusTotal (if configured)
        if ($this->virusTotal->isConfigured()) {
            $collectors['virustotal'] = $this->collectVirusTotal($ip);
        }

        // Collector 4: Microsoft SNDS (if configured)
        if ($this->snds->isConfigured()) {
            $collectors['snds'] = $this->collectSnds($ip);
        }

        // Collector 5: Shodan (if configured)
        if ($this->shodan->isConfigured()) {
            $collectors['shodan'] = $this->collectShodan($ip);
        }

        // Collector 5: Open cases
        $collectors['open_cases'] = $this->collectOpenCases($ip);

        // Calculate reputation from all collector data
        $score = $this->calculateScore($collectors);

        // Save to IP record
        $ip->update([
            'reputation_score' => $score,
            'reputation_details' => $collectors,
            'reputation_updated_at' => now(),
        ]);

        // Check if recovery happened
        $this->checkRecovery($ip);

        return [
            'ip' => $ip->ip_address,
            'score' => $score,
            'label' => $ip->reputation_label,
            'collectors' => $collectors,
        ];
    }

    /**
     * Calculate reputation and create case if below threshold.
     */
    public function collectAndEvaluate(IpAddress $ip): array
    {
        $result = $this->collect($ip);

        $result['case_created'] = false;

        if ($result['score'] < $this->caseThreshold && ! $ip->case_created_from_reputation) {
            $case = $this->createReputationCase($ip, $result['score'], $result['collectors']);
            if ($case) {
                $result['case_created'] = true;
                $result['case_number'] = $case->case_number;
            }
        }

        return $result;
    }

    /**
     * Calculate score from collector data without running collectors (use cached data).
     */
    public function recalculateFromCached(IpAddress $ip): float
    {
        $collectors = $ip->reputation_details ?? [];
        $score = $this->calculateScore($collectors);

        $ip->update([
            'reputation_score' => $score,
            'reputation_updated_at' => now(),
        ]);

        return $score;
    }

    // ===========================
    // COLLECTORS
    // ===========================

    protected function collectAbuseIpDb(IpAddress $ip): array
    {
        if (! $this->abuseIpDb->isConfigured()) {
            return ['available' => false];
        }

        try {
            $data = $this->abuseIpDb->check($ip->ip_address, 90);

            if (! $data) {
                return ['available' => true, 'error' => 'API returned null'];
            }

            $score = $data['abuseConfidenceScore'] ?? 0;
            $reports = $data['totalReports'] ?? 0;

            // Update AbuseIPDB fields on IP record
            $ip->update([
                'abuseipdb_score' => $score,
                'abuseipdb_reports' => $reports,
                'abuseipdb_data' => $data,
                'abuseipdb_checked_at' => now(),
            ]);

            return [
                'available' => true,
                'score' => $score,
                'reports' => $reports,
                'country' => $data['countryCode'] ?? null,
                'isp' => $data['isp'] ?? null,
                'usage_type' => $data['usageType'] ?? null,
                'last_reported' => $data['lastReportedAt'] ?? null,
                'penalty' => round($score * ($this->weights['abuseipdb'] ?? 0.5), 2),
            ];
        } catch (\Throwable $e) {
            Log::debug("AbuseIPDB collector failed for {$ip->ip_address}: {$e->getMessage()}");
            return ['available' => true, 'error' => $e->getMessage()];
        }
    }

    protected function collectDnsbl(IpAddress $ip): array
    {
        try {
            $results = $this->dnsbl->checkIp($ip->ip_address);
            $listings = array_filter($results, fn ($r) => ($r['listed'] ?? false));

            // Separate PBL (informational) from actionable
            $actionable = [];
            $informational = [];

            foreach ($listings as $listing) {
                $meaning = strtolower($listing['meaning'] ?? '');
                if (str_contains($meaning, 'pbl') || str_contains($meaning, 'dynamic') || str_contains($meaning, 'residential')) {
                    $informational[] = $listing;
                } else {
                    $actionable[] = $listing;
                }
            }

            $perList = $this->weights['dnsbl_per_list'] ?? 15;
            $max = $this->weights['dnsbl_max'] ?? 40;
            $penalty = min(count($actionable) * $perList, $max);

            return [
                'available' => true,
                'actionable_count' => count($actionable),
                'informational_count' => count($informational),
                'actionable' => array_map(fn ($r) => $r['list'] . ': ' . ($r['meaning'] ?? ''), $actionable),
                'informational' => array_map(fn ($r) => $r['list'] . ': ' . ($r['meaning'] ?? ''), $informational),
                'penalty' => $penalty,
            ];
        } catch (\Throwable $e) {
            Log::debug("DNSBL collector failed for {$ip->ip_address}: {$e->getMessage()}");
            return ['available' => true, 'error' => $e->getMessage()];
        }
    }

    protected function collectVirusTotal(IpAddress $ip): array
    {
        try {
            $data = $this->virusTotal->getIpReputation($ip->ip_address);

            if (! $data) {
                return ['available' => true, 'error' => 'No data'];
            }

            $malicious = $data['malicious'] ?? 0;
            $total = $malicious + ($data['suspicious'] ?? 0) + ($data['harmless'] ?? 0) + ($data['undetected'] ?? 0);
            $ratio = $total > 0 ? ($malicious / $total) * 100 : 0;
            $weight = $this->weights['virustotal'] ?? 0.3;
            $penalty = round(min($ratio * $weight, 30), 2);

            return [
                'available' => true,
                'malicious' => $malicious,
                'suspicious' => $data['suspicious'] ?? 0,
                'harmless' => $data['harmless'] ?? 0,
                'reputation' => $data['reputation'] ?? 0,
                'as_owner' => $data['as_owner'] ?? null,
                'penalty' => $penalty,
            ];
        } catch (\Throwable $e) {
            Log::debug("VirusTotal collector failed for {$ip->ip_address}: {$e->getMessage()}");
            return ['available' => true, 'error' => $e->getMessage()];
        }
    }

    protected function collectSnds(IpAddress $ip): array
    {
        try {
            // Cache SNDS data for the entire batch (fetched once, checked per IP)
            if ($this->sndsCache === null) {
                $this->sndsCache = $this->snds->fetchAllData();
            }

            $data = $this->snds->checkIp($ip->ip_address, $this->sndsCache);

            if (! $data) {
                return ['available' => true, 'found' => false, 'penalty' => 0];
            }

            $filterResult = $data['filter_result'] ?? 'unknown';
            $trapHits = $data['trap_hits'] ?? 0;
            $blocked = $data['blocked'] ?? false;

            // Penalty based on SNDS status
            $penalty = 0;
            if ($blocked) {
                $penalty = 30; // Blocked at Microsoft = severe
            } elseif ($filterResult === 'red') {
                $penalty = 25;
            } elseif ($filterResult === 'yellow') {
                $penalty = 10;
            } elseif ($trapHits > 0) {
                $penalty = min($trapHits * 5, 15);
            }

            $sndsWeight = $this->weights['snds'] ?? 1.0;
            $penalty = round($penalty * $sndsWeight, 2);

            return [
                'available' => true,
                'found' => true,
                'filter_result' => $filterResult,
                'filter_meaning' => SndsService::interpretFilterResult($filterResult),
                'trap_hits' => $trapHits,
                'blocked' => $blocked,
                'sample_count' => $data['sample_count'] ?? 0,
                'penalty' => $penalty,
            ];
        } catch (\Throwable $e) {
            Log::debug("SNDS collector failed for {$ip->ip_address}: {$e->getMessage()}");
            return ['available' => true, 'error' => $e->getMessage(), 'penalty' => 0];
        }
    }

    protected function collectShodan(IpAddress $ip): array
    {
        try {
            $data = $this->shodan->getHostSummary($ip->ip_address);

            if (! $data) {
                return ['available' => true, 'error' => 'No data'];
            }

            $vulnCount = count($data['vulns'] ?? []);
            $perVuln = $this->weights['shodan_vulns'] ?? 5;
            $penalty = min($vulnCount * $perVuln, 25);

            return [
                'available' => true,
                'ports' => $data['ports'] ?? [],
                'vulns' => array_slice($data['vulns'] ?? [], 0, 10),
                'vuln_count' => $vulnCount,
                'os' => $data['os'] ?? null,
                'organization' => $data['organization'] ?? null,
                'penalty' => $penalty,
            ];
        } catch (\Throwable $e) {
            Log::debug("Shodan collector failed for {$ip->ip_address}: {$e->getMessage()}");
            return ['available' => true, 'error' => $e->getMessage()];
        }
    }

    protected function collectOpenCases(IpAddress $ip): array
    {
        $count = AbuseCase::where('target_ip', $ip->ip_address)
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        $perCase = $this->weights['open_cases'] ?? 10;
        $max = $this->weights['open_cases_max'] ?? 20;
        $penalty = min($count * $perCase, $max);

        return [
            'available' => true,
            'count' => $count,
            'penalty' => $penalty,
        ];
    }

    // ===========================
    // SCORE CALCULATION
    // ===========================

    protected function calculateScore(array $collectors): float
    {
        $score = 100.0;

        foreach ($collectors as $name => $data) {
            if (isset($data['penalty']) && is_numeric($data['penalty'])) {
                $score -= (float) $data['penalty'];
            }
        }

        return max(0, min(100, round($score, 2)));
    }

    // ===========================
    // CASE CREATION
    // ===========================

    protected function createReputationCase(IpAddress $ip, float $score, array $collectors): ?AbuseCase
    {
        $reporter = Reporter::firstOrCreate(
            ['email' => 'reputation@system.abuseai'],
            ['name' => 'Abuse AI Reputation System', 'type' => 'feed', 'is_trusted' => true],
        );

        // Build evidence
        $evidence = "=== IP Reputation Alert ===\n";
        $evidence .= "IP: {$ip->ip_address}\n";
        $evidence .= "Server: " . ($ip->server_name ?? 'Unknown') . "\n";
        $evidence .= "Reputation Score: {$score}/100 (threshold: {$this->caseThreshold})\n\n";

        if (isset($collectors['abuseipdb']) && ($collectors['abuseipdb']['score'] ?? 0) > 0) {
            $a = $collectors['abuseipdb'];
            $evidence .= "--- AbuseIPDB ---\n";
            $evidence .= "Confidence: {$a['score']}% | Reports: {$a['reports']}\n";
            $evidence .= "ISP: " . ($a['isp'] ?? 'N/A') . " | Country: " . ($a['country'] ?? 'N/A') . "\n";
            $evidence .= "Last Reported: " . ($a['last_reported'] ?? 'N/A') . "\n\n";
        }

        if (isset($collectors['dnsbl']) && ($collectors['dnsbl']['actionable_count'] ?? 0) > 0) {
            $d = $collectors['dnsbl'];
            $evidence .= "--- DNSBL Blacklists ({$d['actionable_count']}) ---\n";
            foreach ($d['actionable'] ?? [] as $list) {
                $evidence .= "  - {$list}\n";
            }
            $evidence .= "\n";
        }

        if (isset($collectors['virustotal']) && ($collectors['virustotal']['malicious'] ?? 0) > 0) {
            $v = $collectors['virustotal'];
            $evidence .= "--- VirusTotal ---\n";
            $evidence .= "Malicious: {$v['malicious']} | Suspicious: " . ($v['suspicious'] ?? 0) . "\n\n";
        }

        if (isset($collectors['snds']) && ($collectors['snds']['found'] ?? false)) {
            $sn = $collectors['snds'];
            $evidence .= "--- Microsoft SNDS ---\n";
            $evidence .= "Filter: " . ucfirst($sn['filter_result'] ?? 'unknown') . " (" . ($sn['filter_meaning'] ?? '') . ")\n";
            if ($sn['blocked'] ?? false) $evidence .= "STATUS: BLOCKED at Microsoft\n";
            if (($sn['trap_hits'] ?? 0) > 0) $evidence .= "Spam Trap Hits: {$sn['trap_hits']}\n";
            $evidence .= "\n";
        }

        if (isset($collectors['shodan']) && ($collectors['shodan']['vuln_count'] ?? 0) > 0) {
            $s = $collectors['shodan'];
            $evidence .= "--- Shodan ---\n";
            $evidence .= "Vulnerabilities: {$s['vuln_count']}\n";
            $evidence .= "Ports: " . implode(', ', array_slice($s['ports'] ?? [], 0, 15)) . "\n\n";
        }

        if (isset($collectors['open_cases']) && ($collectors['open_cases']['count'] ?? 0) > 0) {
            $evidence .= "--- Existing Open Cases: {$collectors['open_cases']['count']} ---\n\n";
        }

        // Determine abuse type
        $abuseType = $this->guessAbuseType($collectors);

        $normalizer = app(ReportNormalizerService::class);
        $report = $normalizer->fromFeed([
            'abuse_type' => $abuseType,
            'target_ip' => $ip->ip_address,
            'evidence' => $evidence,
            'reported_at' => now(),
        ], $reporter);

        $caseCreator = app(CaseCreatorService::class);
        $case = $caseCreator->findOrCreateCase($report);

        if ($case) {
            $ip->update(['case_created_from_reputation' => true]);

            CaseAction::create([
                'case_id' => $case->id,
                'action_type' => ActionType::Opened,
                'payload' => [
                    'source' => 'reputation_system',
                    'reputation_score' => $score,
                    'threshold' => $this->caseThreshold,
                ],
                'note' => "Auto-created: IP reputation {$score}/100 (below threshold {$this->caseThreshold})",
                'created_at' => now(),
            ]);

            Log::info("Reputation case for {$ip->ip_address}: score={$score}, case={$case->case_number}");
        }

        return $case;
    }

    protected function guessAbuseType(array $collectors): string
    {
        // DNSBL listings indicate specific types
        foreach ($collectors['dnsbl']['actionable'] ?? [] as $list) {
            $lower = strtolower($list);
            if (str_contains($lower, 'exploit') || str_contains($lower, 'compromised') || str_contains($lower, 'xbl')) {
                return 'malware';
            }
            if (str_contains($lower, 'botnet')) {
                return 'botnet';
            }
        }

        // High AbuseIPDB = likely spam
        if (($collectors['abuseipdb']['score'] ?? 0) > 50) {
            return 'spam';
        }

        // Shodan vulns = intrusion risk
        if (($collectors['shodan']['vuln_count'] ?? 0) > 3) {
            return 'intrusion';
        }

        return 'other';
    }

    protected function checkRecovery(IpAddress $ip): void
    {
        if ($ip->case_created_from_reputation && $ip->reputation_score >= $this->recoveryThreshold) {
            $ip->update(['case_created_from_reputation' => false]);
            Log::info("IP {$ip->ip_address} reputation recovered to {$ip->reputation_score}, case flag reset");
        }
    }
}
