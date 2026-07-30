<?php

namespace App\Jobs\Processing;

use App\Models\AbuseReport;
use App\Services\Enrichment\ThreatIntelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public AbuseReport $report,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(ThreatIntelService $threatIntel): void
    {
        $enrichment = $this->report->enrichment ?? [];

        // IP enrichment (AbuseIPDB + VirusTotal + Shodan + DNSBL)
        if ($this->report->target_ip) {
            try {
                $ipIntel = $threatIntel->enrichIp($this->report->target_ip);
                $enrichment['ip_intel'] = $ipIntel;
                $enrichment['threat_score'] = $ipIntel['threat_score'] ?? 0;
            } catch (\Throwable $e) {
                Log::warning('IP enrichment failed', ['ip' => $this->report->target_ip, 'error' => $e->getMessage()]);
                $enrichment['ip_intel'] = ['error' => $e->getMessage()];
            }

            // Reverse DNS
            try {
                $rdns = gethostbyaddr($this->report->target_ip);
                if ($rdns !== $this->report->target_ip) {
                    $enrichment['rdns'] = $rdns;
                }
            } catch (\Throwable) {
            }
        }

        // Domain enrichment (VirusTotal)
        if ($this->report->target_domain) {
            try {
                $domainIntel = $threatIntel->enrichDomain($this->report->target_domain);
                $enrichment['domain_intel'] = $domainIntel;
            } catch (\Throwable $e) {
                Log::warning('Domain enrichment failed', ['domain' => $this->report->target_domain, 'error' => $e->getMessage()]);
            }
        }

        $enrichment['enriched_at'] = now()->toIso8601String();

        $this->report->update(['enrichment' => $enrichment]);

        Log::info('Report enriched', [
            'report_id' => $this->report->id,
            'sources' => $enrichment['ip_intel']['sources'] ?? [],
            'threat_score' => $enrichment['threat_score'] ?? 0,
        ]);
    }
}
