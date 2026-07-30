<?php

namespace App\Jobs\AI;

use App\Models\AbuseReport;
use App\Services\AI\TranslationAiService;
use App\Services\AI\TriageAiService;
use App\Services\ReporterReputationService;
use App\Support\Attachments\AttachmentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TriageReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public AbuseReport $report,
    ) {
        $this->onQueue('ai-triage');
    }

    public function handle(TriageAiService $triage, TranslationAiService $translator): void
    {
        $content = $this->report->evidence ?? $this->report->raw_payload;
        $metadata = $this->report->metadata ?? [];

        // Step 0: Pull readable text out of report attachments (PDFs,
        // logs, .eml, etc.) and prepend it to the content so classify /
        // noise / IOC extraction all see what the reporter actually
        // attached — not just the description field they typed.
        $attachmentPaths = $this->report->attachment_paths ?? [];
        if (! empty($attachmentPaths)) {
            $extracted = [];
            $attachText = AttachmentTextExtractor::fromPaths($attachmentPaths, $extracted);
            if ($attachText !== '') {
                $content = $attachText . "\n\n=== Reporter Description ===\n" . ($content ?? '');
                if (! empty($extracted)) {
                    $metadata['attachments_text_extracted'] = $extracted;
                }
            }
        }

        // Step 1: Translate non-English content
        $translation = $translator->detectAndTranslate($content);
        if (! $translation['is_english']) {
            $content = $translation['translated'];
            $metadata['original_language'] = $translation['language'];
            $metadata['language_name'] = $translation['language_name'] ?? null;
            $metadata['original_text'] = $translation['original'];
            $metadata['translated'] = true;

            Log::info('Report translated', [
                'report_id' => $this->report->id,
                'from' => $translation['language'],
            ]);
        }

        // Step 2: Classify + noise + IOC extraction in a SINGLE AI call.
        // The combined prompt sees the same content (description +
        // attachment text) for all three judgements, and the result is
        // cached by content hash so re-triage and split-sibling triage
        // don't re-bill the API.
        $reporter = $this->report->reporter;
        $reporterContext = array_filter([
            'reporter_name' => $reporter?->name,
            'reporter_email' => $reporter?->email,
            'reporter_email_domain' => $reporter?->email ? substr(strrchr($reporter->email, '@') ?: '', 1) : null,
            'reporter_source' => $this->report->source,
        ]);

        $combined = $triage->triageAll($content, $reporterContext);
        $classification = $combined['classification'] ?? null;
        $noise = $combined['noise'] ?? null;
        $parsed = $combined['iocs'] ?? null;

        if ($classification) {
            $this->report->update(['ai_classification' => $classification]);

            $isNotAbuse = ($classification['type'] ?? '') === 'not_abuse'
                || ($classification['is_abuse_report'] ?? true) === false;

            if ($isNotAbuse) {
                $metadata['flagged_as_not_abuse'] = true;
                $metadata['not_abuse_reason'] = $classification['summary'] ?? 'AI classified as non-abuse content';
                $this->report->update([
                    'ai_noise_score' => 1.0,
                    'metadata' => array_merge($this->report->metadata ?? [], $metadata),
                ]);

                Log::info('AI triage: not an abuse report', [
                    'report_id' => $this->report->id,
                    'summary' => $classification['summary'] ?? null,
                ]);

                return; // Skip further processing for non-abuse content
            }

            // Update abuse_type if AI is confident
            $confidence = $classification['confidence'] ?? 0;
            if ($confidence >= 0.7 && isset($classification['type'])) {
                $validTypes = ['spam', 'phishing', 'malware', 'ddos', 'csam', 'copyright', 'fraud',
                    'law_enforcement', 'brute_force', 'intrusion', 'botnet', 'other'];
                if (in_array($classification['type'], $validTypes)) {
                    $this->report->update(['abuse_type' => $classification['type']]);
                }
            }
        }

        if ($noise) {
            $noiseScore = $noise['noise_score'] ?? null;
            $noiseIsNotAbuse = $noise['is_not_abuse'] ?? false;

            if ($noiseIsNotAbuse || ($noiseScore !== null && $noiseScore >= 0.95)) {
                $metadata['flagged_as_not_abuse'] = true;
                $metadata['not_abuse_reason'] = $noise['reason'] ?? 'High noise score — likely not an abuse report';
            }

            $this->report->update(['ai_noise_score' => $noiseScore]);
            $metadata['noise_reason'] = $noise['reason'] ?? null;

            if (($noiseScore ?? 0) > 0.8) {
                $metadata['flagged_as_noise'] = true;
            }
        }
        if ($parsed) {
            $enrichment = $this->report->enrichment ?? [];
            $enrichment['parsed_iocs'] = $parsed;
            $this->report->update(['enrichment' => $enrichment]);

            // Prefer the new target_* arrays; fall back to legacy ips/domains/urls
            // for any provider/prompt revision that still returns the old shape.
            $targetIps = $parsed['target_ips'] ?? $parsed['ips'] ?? [];
            $targetDomains = $parsed['target_domains'] ?? $parsed['domains'] ?? [];
            $targetUrls = $parsed['target_urls'] ?? $parsed['urls'] ?? [];

            $reporterDomain = $reporterContext['reporter_email_domain'] ?? null;
            $reporterDomains = array_map('strtolower', $parsed['reporter_domains'] ?? []);
            if ($reporterDomain) {
                $reporterDomains[] = strtolower($reporterDomain);
            }
            $reporterIps = $parsed['reporter_ips'] ?? [];

            $isReporterDomain = function (?string $d) use ($reporterDomains): bool {
                if (! $d) {
                    return false;
                }
                $d = strtolower($d);
                foreach ($reporterDomains as $rd) {
                    if ($d === $rd || str_ends_with($d, '.' . $rd)) {
                        return true;
                    }
                }
                return false;
            };

            // Fill target_ip with the first non-reporter IP and attach
            // every additional IP onto extra_target_ips on the SAME
            // report and case. One report = one case; extras are
            // listed in the UI alongside the primary so agents see
            // every IP the abuse touches without fragmenting cases.
            app(\App\Services\CaseEngine\ReportSplitter::class)
                ->attachExtraIps($this->report, $targetIps, $reporterIps);
            $this->report->refresh();

            // Auto-fill target_domain only if it's not the reporter's own domain.
            if (empty($this->report->target_domain)) {
                foreach ($targetDomains as $domain) {
                    if ($domain && ! $isReporterDomain($domain)) {
                        $this->report->update(['target_domain' => $domain]);
                        break;
                    }
                }
            }

            // Same guard for target_url.
            if (empty($this->report->target_url)) {
                foreach ($targetUrls as $url) {
                    $host = parse_url((string) $url, PHP_URL_HOST);
                    if ($url && ! $isReporterDomain($host)) {
                        $this->report->update(['target_url' => $url]);
                        break;
                    }
                }
            }

            // If the only target identifier we got is a URL, fall back to its
            // host as the target_domain. The case engine groups on
            // target_ip/target_domain (cases don't carry target_url), so
            // without this a phishing report that only contains a link gets
            // rejected as no_target_identifier.
            if (empty($this->report->target_domain) && $this->report->target_url) {
                $host = parse_url((string) $this->report->target_url, PHP_URL_HOST);
                if ($host && ! $isReporterDomain($host)) {
                    $this->report->update(['target_domain' => $host]);
                }
            }

            // Persist AI-derived issue summary + abuse_type hint into metadata
            // so agents can see *why* this was flagged at a glance.
            if (! empty($parsed['issue_summary']) || ! empty($parsed['abuse_type'])) {
                $metadata['ai_parsed_issue'] = $parsed['issue_summary'] ?? null;
                $metadata['ai_parsed_abuse_type'] = $parsed['abuse_type'] ?? null;
            }

            // Pull the actual abuse-occurrence time from the AI's
            // timestamps[] (NOT the email-received time) so agents
            // know when the attack happened, not when the report landed.
            \App\Support\AbuseTimestampParser::applyToReport($this->report, $parsed);
            \App\Support\ExternalCaseNumberCollector::applyToReport($this->report, $parsed);
        }

        // Save metadata
        if (! empty($metadata)) {
            $this->report->update(['metadata' => array_merge($this->report->metadata ?? [], $metadata)]);
        }

        // Retry case creation when this triage pass just filled in a
        // target identifier that the synchronous HandleReportReceived
        // run couldn't see (typical for form submissions where the IPs
        // live inside an attached PDF — the pre-screen doesn't have
        // time to extract attachments, so the case was skipped with
        // skipped_reason=no_target_identifier).
        $this->report->refresh();
        $skipReason = $this->report->metadata['skipped_reason'] ?? null;
        $hasTarget = $this->report->target_ip || $this->report->target_domain;
        if (! $this->report->case_id && $skipReason === 'no_target_identifier' && $hasTarget) {
            try {
                $caseCreator = app(\App\Services\CaseEngine\CaseCreatorService::class);
                $case = $caseCreator->findOrCreateCase($this->report);
                if ($case) {
                    Log::info('Triage retry created case after IOC extraction', [
                        'report_id' => $this->report->id,
                        'case_number' => $case->case_number,
                        'target_ip' => $this->report->target_ip,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Triage retry case creation failed', [
                    'report_id' => $this->report->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('AI triage completed', [
            'report_id' => $this->report->id,
            'classification' => $classification['type'] ?? 'unknown',
            'confidence' => $classification['confidence'] ?? 0,
            'noise_score' => $noise['noise_score'] ?? null,
            'translated' => ! ($translation['is_english'] ?? true),
            'iocs_found' => count($parsed['target_ips'] ?? $parsed['ips'] ?? [])
                + count($parsed['target_domains'] ?? $parsed['domains'] ?? []),
        ]);

        // Adjust reporter reputation based on triage outcome
        $this->adjustReporterReputation($classification, $noise);
    }

    protected function adjustReporterReputation(?array $classification, ?array $noise): void
    {
        $reporter = $this->report->reporter;
        if (! $reporter) {
            return;
        }

        try {
            $reputation = app(ReporterReputationService::class);
            $noiseScore = (float) ($noise['noise_score'] ?? 0);
            $confidence = (float) ($classification['confidence'] ?? 0.5);

            $isNotAbuse = ($classification['type'] ?? '') === 'not_abuse'
                || ($classification['is_abuse_report'] ?? true) === false;

            if ($isNotAbuse) {
                $reputation->adjustForReport($reporter, 'not_abuse', $confidence);
            } elseif ($noiseScore > 0.8) {
                $reputation->adjustForReport($reporter, 'noise', $confidence);
            } elseif ($this->report->is_duplicate) {
                $reputation->adjustForReport($reporter, 'duplicate');
            } else {
                $reputation->adjustForReport($reporter, 'valid_abuse', $confidence);
            }
        } catch (\Throwable $e) {
            Log::warning('Reporter reputation adjustment failed', [
                'report_id' => $this->report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
