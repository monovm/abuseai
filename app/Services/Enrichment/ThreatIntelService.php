<?php

namespace App\Services\Enrichment;

use App\Services\Ingestion\Feeds\DnsblService;
use Illuminate\Support\Facades\Log;

/**
 * Aggregates all threat intelligence sources for a target.
 */
class ThreatIntelService
{
    public function __construct(
        protected VirusTotalService $virusTotal,
        protected ShodanService $shodan,
        protected AbuseIpDbService $abuseIpDb,
        protected DnsblService $dnsbl,
    ) {}

    /**
     * Full enrichment for an IP address.
     */
    public function enrichIp(string $ip): array
    {
        $enrichment = ['ip' => $ip, 'sources' => []];

        // AbuseIPDB
        if ($this->abuseIpDb->isConfigured()) {
            $abuseData = $this->abuseIpDb->check($ip, 90);
            if ($abuseData) {
                $enrichment['abuseipdb'] = [
                    'score' => $abuseData['abuseConfidenceScore'] ?? 0,
                    'total_reports' => $abuseData['totalReports'] ?? 0,
                    'country' => $abuseData['countryCode'] ?? null,
                    'isp' => $abuseData['isp'] ?? null,
                    'usage_type' => $abuseData['usageType'] ?? null,
                    'last_reported' => $abuseData['lastReportedAt'] ?? null,
                ];
                $enrichment['sources'][] = 'abuseipdb';
            }
        }

        // VirusTotal
        if ($this->virusTotal->isConfigured()) {
            $vtData = $this->virusTotal->getIpReputation($ip);
            if ($vtData) {
                $enrichment['virustotal'] = $vtData;
                $enrichment['sources'][] = 'virustotal';
            }
        }

        // Shodan
        if ($this->shodan->isConfigured()) {
            $shodanData = $this->shodan->getHostSummary($ip);
            if ($shodanData) {
                $enrichment['shodan'] = $shodanData;
                $enrichment['sources'][] = 'shodan';
            }
        }

        // DNSBL
        $dnsblResults = $this->dnsbl->checkIp($ip);
        $listings = array_filter($dnsblResults, fn ($r) => $r['listed'] ?? false);
        if (! empty($listings)) {
            $enrichment['dnsbl'] = [
                'listed' => true,
                'lists' => array_map(fn ($r) => $r['list'] . ': ' . $r['meaning'], $listings),
                'count' => count($listings),
            ];
            $enrichment['sources'][] = 'dnsbl';
        } else {
            $enrichment['dnsbl'] = ['listed' => false, 'lists' => [], 'count' => 0];
            $enrichment['sources'][] = 'dnsbl';
        }

        // Compute threat score (0-100)
        $enrichment['threat_score'] = $this->computeThreatScore($enrichment);

        return $enrichment;
    }

    /**
     * Full enrichment for a domain.
     */
    public function enrichDomain(string $domain): array
    {
        $enrichment = ['domain' => $domain, 'sources' => []];

        // VirusTotal
        if ($this->virusTotal->isConfigured()) {
            $vtData = $this->virusTotal->getDomainReputation($domain);
            if ($vtData) {
                $enrichment['virustotal'] = $vtData;
                $enrichment['sources'][] = 'virustotal';
            }
        }

        return $enrichment;
    }

    /**
     * Compute a combined threat score from all sources.
     */
    protected function computeThreatScore(array $enrichment): float
    {
        $score = 0;
        $factors = 0;

        // AbuseIPDB (0-100 already)
        if (isset($enrichment['abuseipdb']['score'])) {
            $score += $enrichment['abuseipdb']['score'];
            $factors++;
        }

        // VirusTotal (malicious detections ratio)
        if (isset($enrichment['virustotal']['malicious'])) {
            $total = ($enrichment['virustotal']['malicious'] ?? 0) + ($enrichment['virustotal']['harmless'] ?? 0) + ($enrichment['virustotal']['undetected'] ?? 0);
            if ($total > 0) {
                $vtScore = ($enrichment['virustotal']['malicious'] / $total) * 100;
                $score += $vtScore;
                $factors++;
            }
        }

        // DNSBL (listed on any = bad)
        if (isset($enrichment['dnsbl']['count'])) {
            $dnsblScore = min($enrichment['dnsbl']['count'] * 20, 100);
            $score += $dnsblScore;
            $factors++;
        }

        // Shodan (vulns = bad)
        if (isset($enrichment['shodan']['vulns'])) {
            $vulnScore = min(count($enrichment['shodan']['vulns']) * 15, 100);
            $score += $vulnScore;
            $factors++;
        }

        return $factors > 0 ? min(round($score / $factors, 2), 100) : 0;
    }
}
