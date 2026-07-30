<?php

namespace App\Listeners;

use App\Enums\AbuseType;
use App\Events\ReportReceived;
use App\Jobs\AI\TriageReport;
use App\Jobs\Processing\EnrichReport;
use App\Models\AbuseReport;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Services\AI\TriageAiService;
use App\Services\CaseEngine\CaseCreatorService;
use App\Services\Ingestion\DeduplicationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HandleReportReceived
{
    public function __construct(
        protected DeduplicationService $dedup,
        protected CaseCreatorService $caseCreator,
    ) {}

    public function handle(ReportReceived $event): void
    {
        $report = $event->report;

        // Wrap the whole flow so any unexpected throw — AI client error,
        // dedup Redis hiccup, infrastructure-lookup timeout — is captured
        // as a visible reason on the report instead of silently leaving
        // it in "Unprocessed" state with no clue to the operator.
        try {
            $this->process($report);
        } catch (\Throwable $e) {
            Log::error('HandleReportReceived failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $report->update([
                'metadata' => array_merge($report->metadata ?? [], [
                    'processing_error' => $e->getMessage(),
                    'processing_error_at' => now()->toIso8601String(),
                ]),
            ]);
        }
    }

    protected function process(AbuseReport $report): void
    {
        $report->loadMissing('reporter');
        $isLawEnforcement = (bool) $report->reporter?->is_law_enforcement;

        // Step 1: Check if target IP is in our inventory (must be active to create case)
        if ($report->target_ip) {
            // findByIp returns the matching host row or the narrowest enclosing block.
            $ipRecord = IpAddress::findByIp($report->target_ip);
            $isActive = $ipRecord && $ipRecord->status === 'active';

            $report->update([
                'metadata' => array_merge($report->metadata ?? [], [
                    'ip_in_inventory' => (bool) $ipRecord,
                    'ip_active' => $isActive,
                    'ip_status' => $ipRecord?->status,
                    'ip_server_name' => $ipRecord?->server_name,
                ]),
            ]);
        }

        // Step 1b: AI pre-screening — classify before case creation.
        // Skip for law enforcement reports: LE always opens a case regardless of noise score.
        if (! $isLawEnforcement) {
            $alreadyScreened = $report->metadata['pre_screened'] ?? false;
            if (! $alreadyScreened) {
                $this->aiPreScreen($report);
            }

            // If flagged as not abuse, skip case creation
            if ($report->metadata['flagged_as_not_abuse'] ?? false) {
                Log::info('Report skipped: flagged as not abuse', [
                    'report_id' => $report->id,
                    'reason' => $report->metadata['not_abuse_reason'] ?? 'AI classified as non-abuse',
                ]);

                // Still dispatch enrichment for record keeping, but skip case creation
                try {
                    EnrichReport::dispatch($report);
                } catch (\Throwable) {
                }

                $report->reporter?->increment('report_count');

                return;
            }
        }

        // Step 2: Dedup check + case creation (run synchronously — fast and critical).
        // Law enforcement reports always proceed to the case engine; dedup still runs
        // so follow-ups link to the existing parent case, but we never skip creation.
        $isDuplicate = $this->dedup->isDuplicate($report);
        if (! $isDuplicate || $isLawEnforcement) {
            $case = $this->caseCreator->findOrCreateCase($report);

            if ($case) {
                Log::info('Case created/linked for report', [
                    'report_id' => $report->id,
                    'case_number' => $case->case_number,
                    'law_enforcement' => $isLawEnforcement,
                ]);
            }
        }

        // Step 3: Dispatch async jobs (enrichment + AI triage)
        // These can run later via queue workers — non-blocking
        try {
            EnrichReport::dispatch($report);
            TriageReport::dispatch($report);
        } catch (\Throwable $e) {
            // Queue not available — skip async jobs, case is already created
            Log::warning('Could not dispatch async jobs: ' . $e->getMessage());
        }

        // Increment reporter's report count
        $report->reporter?->increment('report_count');
    }

    /**
     * Run synchronous AI classification to filter non-abuse content before case creation.
     */
    protected function aiPreScreen($report): void
    {
        try {
            $triage = app(TriageAiService::class);
            $content = $report->evidence ?? $report->raw_payload ?? '';

            // Pull text out of attached PDFs / images / .eml / logs so
            // pre-screen sees the same content the queued TriageReport
            // would. Without this, form submissions where the real
            // evidence lives in an attached file get mis-classified
            // as not-abuse and the case is skipped.
            $attachmentPaths = $report->attachment_paths ?? [];
            if (! empty($attachmentPaths)) {
                $attachText = \App\Support\Attachments\AttachmentTextExtractor::fromPaths($attachmentPaths);
                if ($attachText !== '') {
                    $content = $attachText . "\n\n=== Reporter Description ===\n" . $content;
                }
            }

            if (empty($content)) {
                return;
            }

            // Single combined call (classify + noise + IOCs). Result is
            // cached by content hash inside TriageAiService, so the
            // queued TriageReport job will get a cache hit and consume
            // zero additional API tokens.
            $reporter = $report->reporter;
            $reporterContext = array_filter([
                'reporter_name' => $reporter?->name,
                'reporter_email' => $reporter?->email,
                'reporter_email_domain' => $reporter?->email ? substr(strrchr($reporter->email, '@') ?: '', 1) : null,
                'reporter_source' => $report->source,
            ]);

            $combined = $triage->triageAll($content, $reporterContext);
            $classification = $combined['classification'] ?? null;

            if (! $classification) {
                return;
            }

            $updates = [
                'ai_classification' => $classification,
                'metadata' => array_merge($report->metadata ?? [], [
                    'pre_screened' => true,
                ]),
            ];

            // Persist noise + IOC results too so TriageReport (or
            // re-triage) can pick them up without a second AI round trip.
            if (isset($combined['noise']['noise_score'])) {
                $updates['ai_noise_score'] = $combined['noise']['noise_score'];
            }
            if (! empty($combined['iocs'])) {
                $enrichment = $report->enrichment ?? [];
                $enrichment['parsed_iocs'] = $combined['iocs'];
                $updates['enrichment'] = $enrichment;
            }

            $report->update($updates);

            $isNotAbuse = ($classification['type'] ?? '') === 'not_abuse'
                || ($classification['is_abuse_report'] ?? true) === false;

            if ($isNotAbuse) {
                $report->update([
                    'ai_noise_score' => 1.0,
                    'metadata' => array_merge($report->metadata ?? [], [
                        'flagged_as_not_abuse' => true,
                        'not_abuse_reason' => $classification['summary'] ?? 'AI classified as non-abuse content',
                        'pre_screened' => true,
                    ]),
                ]);
                $this->logAiDecision($report, $classification, 'skipped');
            } else {
                $this->logAiDecision($report, $classification, 'passed');

                $confidence = $classification['confidence'] ?? 0;
                if ($confidence >= 0.7 && isset($classification['type'])) {
                    $validTypes = ['spam', 'phishing', 'malware', 'ddos', 'csam', 'copyright', 'fraud',
                        'law_enforcement', 'brute_force', 'intrusion', 'botnet', 'other'];
                    if (in_array($classification['type'], $validTypes)) {
                        $report->update(['abuse_type' => $classification['type']]);
                    }
                }

                // Pre-fill target_ip / target_domain / target_url from extracted
                // IOCs so case creation in the next step can succeed without
                // waiting for the queued TriageReport pass.
                $iocs = $combined['iocs'] ?? [];
                $targetIps = $iocs['target_ips'] ?? [];
                $targetDomains = $iocs['target_domains'] ?? [];
                $targetUrls = $iocs['target_urls'] ?? [];
                $reporterIps = $iocs['reporter_ips'] ?? [];

                $reporterDomain = $reporterContext['reporter_email_domain'] ?? null;
                $reporterDomains = array_map('strtolower', $iocs['reporter_domains'] ?? []);
                if ($reporterDomain) {
                    $reporterDomains[] = strtolower($reporterDomain);
                }
                $isReporterDomain = function (?string $d) use ($reporterDomains): bool {
                    if (! $d) return false;
                    $d = strtolower($d);
                    foreach ($reporterDomains as $rd) {
                        if ($d === $rd || str_ends_with($d, '.' . $rd)) return true;
                    }
                    return false;
                };

                if (empty($report->target_ip)) {
                    foreach ($targetIps as $ip) {
                        if ($ip && ! in_array($ip, $reporterIps, true)) {
                            $report->update(['target_ip' => $ip]);
                            break;
                        }
                    }
                }
                if (empty($report->target_domain)) {
                    foreach ($targetDomains as $domain) {
                        if ($domain && ! $isReporterDomain($domain)) {
                            $report->update(['target_domain' => $domain]);
                            break;
                        }
                    }
                }
                if (empty($report->target_url)) {
                    foreach ($targetUrls as $url) {
                        $host = parse_url((string) $url, PHP_URL_HOST);
                        if ($url && ! $isReporterDomain($host)) {
                            $report->update(['target_url' => $url]);
                            break;
                        }
                    }
                }

                // URL-only reports (typical phishing tip with just a link) need
                // a domain derived from the URL host so the case engine can
                // group them — cases don't carry target_url directly.
                if (empty($report->target_domain) && $report->target_url) {
                    $host = parse_url((string) $report->target_url, PHP_URL_HOST);
                    if ($host && ! $isReporterDomain($host)) {
                        $report->update(['target_domain' => $host]);
                    }
                }

                // Set abuse_occurred_at from the AI's extracted incident
                // timestamps before the case engine creates the case, so
                // the case captures the actual attack time at creation.
                \App\Support\AbuseTimestampParser::applyToReport($report, $iocs);
                \App\Support\ExternalCaseNumberCollector::applyToReport($report, $iocs);
            }
        } catch (\Throwable $e) {
            Log::warning('AI pre-screening failed for report, proceeding without', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log AI pre-screening decision to audit trail.
     */
    protected function logAiDecision($report, array $classification, string $decision): void
    {
        try {
            AuditLog::create([
                'entity_type' => 'AbuseReport',
                'entity_id' => $report->id,
                'event' => 'ai_pre_screen',
                'new_values' => [
                    'decision' => $decision,
                    'ai_type' => $classification['type'] ?? null,
                    'ai_confidence' => $classification['confidence'] ?? null,
                    'ai_summary' => $classification['summary'] ?? null,
                    'source' => $report->source,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
