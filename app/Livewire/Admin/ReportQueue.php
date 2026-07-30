<?php

namespace App\Livewire\Admin;

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Jobs\AI\TriageReport;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Services\CaseEngine\CaseCreatorService;
use App\Support\JsonColumnSearch;
use Livewire\Component;
use Livewire\WithPagination;

class ReportQueue extends Component
{
    use WithPagination;

    public string $filterSource = '';
    public string $filterType = '';
    public string $filterNoise = '';
    public string $filterStatus = '';
    public string $filterReporter = '';
    public string $filterTarget = '';
    public string $search = '';
    public string $sortBy = 'reported_at';
    public string $sortDir = 'desc';
    public array $selectedReports = [];
    public bool $selectAll = false;

    // Manual IP attachment per report
    public string $editIpReportId = '';
    public string $manualReportIp = '';

    public function openReportIpForm(string $reportId): void
    {
        $this->editIpReportId = $reportId;
        $report = AbuseReport::find($reportId);
        $this->manualReportIp = $report?->target_ip ?? '';
        $this->resetErrorBag('manualReportIp');
    }

    public function cancelReportIpForm(): void
    {
        $this->editIpReportId = '';
        $this->manualReportIp = '';
        $this->resetErrorBag('manualReportIp');
    }

    public function attachReportIp(): void
    {
        $this->validate([
            'manualReportIp' => ['required', 'ip'],
            'editIpReportId' => ['required', 'uuid'],
        ]);

        $report = AbuseReport::find($this->editIpReportId);
        if (! $report) {
            session()->flash('error', 'Report not found.');
            $this->cancelReportIpForm();
            return;
        }

        $newIp = \App\Support\IpHelpers::canonicalize($this->manualReportIp) ?? trim($this->manualReportIp);
        $previous = $report->target_ip;

        $metadata = $report->metadata ?? [];
        $metadata['manual_target_ip'] = [
            'set_by' => auth()->id(),
            'set_at' => now()->toIso8601String(),
            'previous' => $previous,
        ];

        $report->update([
            'target_ip' => $newIp,
            'metadata' => $metadata,
        ]);

        session()->flash('success', $previous
            ? "Report target IP updated to {$newIp}."
            : "Report target IP set to {$newIp}.");

        $this->cancelReportIpForm();
    }

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $query = $this->buildQuery();
            $this->selectedReports = $query->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedReports = [];
        }
    }

    public function bulkMarkNotAbuse(): void
    {
        if (empty($this->selectedReports)) {
            return;
        }

        $count = 0;
        foreach (AbuseReport::whereIn('id', $this->selectedReports)->get() as $report) {
            $metadata = $report->metadata ?? [];
            $metadata['flagged_as_not_abuse'] = true;
            $report->update([
                'metadata' => $metadata,
                'ai_noise_score' => 1.0,
            ]);
            $count++;
        }

        $this->selectedReports = [];
        $this->selectAll = false;
        session()->flash('success', "{$count} report(s) marked as not abuse.");
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedReports)) {
            return;
        }

        $deleted = AbuseReport::whereIn('id', $this->selectedReports)
            ->whereNull('case_id')
            ->delete();

        $this->selectedReports = [];
        $this->selectAll = false;
        session()->flash('success', "{$deleted} unlinked report(s) deleted.");
    }

    public function createCaseFromReport(string $reportId): void
    {
        $report = AbuseReport::find($reportId);

        if (! $report) {
            session()->flash('error', 'Report not found.');
            return;
        }

        if ($report->case_id) {
            session()->flash('error', 'Report is already linked to a case.');
            return;
        }

        $caseCreator = app(CaseCreatorService::class);
        $case = $caseCreator->findOrCreateCase($report);

        if ($case) {
            session()->flash('success', "Case {$case->case_number} created and linked to report.");
            return;
        }

        // findOrCreateCase returned null (e.g. IP not in inventory), create manually
        $case = AbuseCase::create([
            'case_number' => AbuseCase::generateCaseNumber(),
            'status' => CaseStatus::Open,
            'abuse_type' => $report->abuse_type,
            'severity_score' => 0,
            'severity_level' => SeverityLevel::Low,
            'target_ip' => $report->target_ip,
            'target_domain' => $report->target_domain,
            'report_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $report->update(['case_id' => $case->id]);

        session()->flash('success', "Case {$case->case_number} manually created from report.");
    }

    public function retriageReport(string $reportId): void
    {
        $report = AbuseReport::find($reportId);

        if (! $report) {
            session()->flash('error', 'Report not found.');
            return;
        }

        // Clear previous AI results
        $metadata = $report->metadata ?? [];
        unset($metadata['flagged_as_not_abuse'], $metadata['flagged_as_noise'], $metadata['pre_screened'], $metadata['not_abuse_reason'], $metadata['noise_reason']);

        $report->update([
            'ai_classification' => null,
            'ai_noise_score' => null,
            'metadata' => $metadata,
        ]);

        // Run triage synchronously for immediate feedback (use evidence first, fall back to raw_payload)
        // Extend PHP timeout since AI API calls can take a while
        set_time_limit(180);

        try {
            $triage = app(\App\Services\AI\TriageAiService::class);
            $content = $report->evidence ?? $report->raw_payload ?? '';

            // Pull text out of any attached PDFs / .eml / logs so re-triage
            // sees the same content the queued TriageReport job would.
            $attachmentPaths = $report->attachment_paths ?? [];
            if (! empty($attachmentPaths)) {
                $extracted = [];
                $attachText = \App\Support\Attachments\AttachmentTextExtractor::fromPaths($attachmentPaths, $extracted);
                if ($attachText !== '') {
                    $content = $attachText . "\n\n=== Reporter Description ===\n" . $content;
                    $metadata['attachments_text_extracted'] = $extracted;
                }
            }

            if (empty($content)) {
                session()->flash('error', 'No content to classify — report has no evidence, payload, or readable attachments.');
                return;
            }

            \Illuminate\Support\Facades\Log::info('Re-triage started', [
                'report_id' => $reportId,
                'content_length' => strlen($content),
                'attachments_extracted' => $extracted ?? [],
            ]);

            // Single combined call: classify + noise + IOCs in one
            // request. Cached by content hash, so re-triage on the
            // same content (including the same PDF attachment text)
            // costs zero API tokens after the first run.
            $reporter = $report->reporter;
            $reporterContext = array_filter([
                'reporter_name' => $reporter?->name,
                'reporter_email' => $reporter?->email,
                'reporter_email_domain' => $reporter?->email ? substr(strrchr($reporter->email, '@') ?: '', 1) : null,
                'reporter_source' => $report->source,
            ]);

            // forceFresh: a manual re-triage must actually re-call the
            // provider. Serving the cached result would instantly replay
            // the same outcome the operator is trying to correct.
            $combined = $triage->triageAll($content, $reporterContext, forceFresh: true);
            $classification = is_array($combined) ? ($combined['classification'] ?? null) : null;
            $noise = is_array($combined) ? ($combined['noise'] ?? null) : null;
            $parsed = is_array($combined) ? ($combined['iocs'] ?? null) : null;

            \Illuminate\Support\Facades\Log::info('Re-triage combined result', [
                'report_id' => $reportId,
                'classification_type' => $classification['type'] ?? null,
                'noise_score' => $noise['noise_score'] ?? null,
                'iocs_count' => is_array($parsed) ? count($parsed['target_ips'] ?? []) : 0,
            ]);

            if (! $classification) {
                // Tell the operator which failure they hit instead of a
                // generic message — the cause is either an empty/blocked
                // provider response or a response missing the expected
                // classification block.
                $combinedKeys = is_array($combined) ? array_keys($combined) : null;
                $diagnostic = $combined === null
                    ? 'the AI provider returned nothing (API error, rate limit, or an empty / unparseable response)'
                    : 'the AI response had no classification block (returned keys: ' . (implode(', ', $combinedKeys) ?: 'none') . ')';

                \Illuminate\Support\Facades\Log::warning('Re-triage: no usable classification', [
                    'report_id' => $reportId,
                    'combined_is_array' => is_array($combined),
                    'combined_keys' => $combinedKeys,
                    'content_length' => strlen($content),
                    'diagnostic' => $diagnostic,
                ]);
                session()->flash('error', "AI triage produced no classification — {$diagnostic}. See the application logs for the provider response.");
                return;
            }

            $isNotAbuse = ($classification['type'] ?? '') === 'not_abuse'
                || ($classification['is_abuse_report'] ?? true) === false;

            $updateData = ['ai_classification' => $classification];
            if ($isNotAbuse) {
                $updateData['ai_noise_score'] = 1.0;
                $metadata['flagged_as_not_abuse'] = true;
                $metadata['not_abuse_reason'] = $classification['summary'] ?? 'AI classified as non-abuse content';
            } else {
                $confidence = $classification['confidence'] ?? 0;
                if ($confidence >= 0.7 && isset($classification['type'])) {
                    $validTypes = ['spam', 'phishing', 'malware', 'ddos', 'csam', 'copyright', 'fraud',
                        'law_enforcement', 'brute_force', 'intrusion', 'botnet', 'other'];
                    if (in_array($classification['type'], $validTypes)) {
                        $updateData['abuse_type'] = $classification['type'];
                    }
                }
            }
            $updateData['metadata'] = array_merge($report->metadata ?? [], $metadata, ['pre_screened' => true]);
            unset($updateData['metadata']['skipped_reason']);
            $report->update($updateData);

            // Noise — already in $combined, no second AI call
            if ($noise) {
                $report->update(['ai_noise_score' => $noise['noise_score'] ?? null]);
                if (($noise['noise_score'] ?? 0) > 0.8) {
                    $report->update([
                        'metadata' => array_merge($report->metadata ?? [], ['flagged_as_noise' => true, 'noise_reason' => $noise['reason'] ?? null]),
                    ]);
                }
            }

            // IOC extraction — already in $combined, no second AI call
            if (! $isNotAbuse) {
                if ($parsed) {
                    $enrichment = $report->enrichment ?? [];
                    $enrichment['parsed_iocs'] = $parsed;
                    $report->update(['enrichment' => $enrichment]);

                    $targetIps = $parsed['target_ips'] ?? $parsed['ips'] ?? [];
                    $targetDomains = $parsed['target_domains'] ?? $parsed['domains'] ?? [];
                    $targetUrls = $parsed['target_urls'] ?? $parsed['urls'] ?? [];
                    $reporterIps = $parsed['reporter_ips'] ?? [];

                    $reporterDomain = $reporterContext['reporter_email_domain'] ?? null;
                    $reporterDomains = array_map('strtolower', $parsed['reporter_domains'] ?? []);
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

                    // Fill primary target_ip + attach extras on this same
                    // report (and propagate to the case once linked).
                    app(\App\Services\CaseEngine\ReportSplitter::class)
                        ->attachExtraIps($report, $targetIps, $reporterIps);
                    $report->refresh();

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

                    // URL-only reports need a target_domain derived from the
                    // URL host so the case engine can group them — cases
                    // don't carry target_url directly.
                    if (empty($report->target_domain) && $report->target_url) {
                        $host = parse_url((string) $report->target_url, PHP_URL_HOST);
                        if ($host && ! $isReporterDomain($host)) {
                            $report->update(['target_domain' => $host]);
                        }
                    }

                    \App\Support\AbuseTimestampParser::applyToReport($report, $parsed);
                    \App\Support\ExternalCaseNumberCollector::applyToReport($report, $parsed);
                }
            }

            $type = $classification['type'] ?? 'unknown';
            $conf = number_format(($classification['confidence'] ?? 0) * 100);
            $message = "AI triage complete: {$type} ({$conf}% confidence)";

            $extraCount = is_array($report->extra_target_ips ?? null) ? count($report->extra_target_ips) : 0;
            if ($extraCount > 0) {
                $message .= " — {$extraCount} extra IP(s) attached to this report.";
            }

            // If classified as abuse and no case linked yet, try to create one
            if (! $isNotAbuse && ! $report->case_id) {
                $report->refresh();
                $caseCreator = app(\App\Services\CaseEngine\CaseCreatorService::class);
                $case = $caseCreator->findOrCreateCase($report);
                if ($case) {
                    $message .= " — Case {$case->case_number} created.";
                    \Illuminate\Support\Facades\Log::info('Re-triage: case created', [
                        'report_id' => $reportId,
                        'case_number' => $case->case_number,
                    ]);
                } else {
                    $skipReason = $report->fresh()->metadata['skipped_reason'] ?? 'unknown';
                    $message .= " — No case created ({$skipReason}).";
                }
            } elseif (! $isNotAbuse && $report->case_id) {
                // Existing case: re-resolve action brand (infra/hostname_pattern)
                // and received-via brand (recipient inbox) against current rules.
                // Bypass the existing-case short-circuit so newly-added patterns
                // actually take effect.
                $report->refresh();
                $case = $report->case;
                if ($case) {
                    $caseCreator = app(\App\Services\CaseEngine\CaseCreatorService::class);
                    $newBrandId = $caseCreator->resolveBrandFromReport($report, ignoreExistingCase: true);
                    $newReceivedViaBrandId = $caseCreator->resolveReceivedViaBrandFromReport($report);

                    $updates = [];
                    if ($newBrandId && $newBrandId !== $case->brand_id) {
                        $updates['brand_id'] = $newBrandId;
                    }
                    if ($newReceivedViaBrandId !== $case->received_via_brand_id) {
                        $updates['received_via_brand_id'] = $newReceivedViaBrandId;
                    }

                    if (! empty($updates)) {
                        $oldBrand = $case->brand?->name ?? 'None';
                        $oldReceivedVia = $case->receivedViaBrand?->name ?? 'None';
                        $case->update($updates);
                        $case->refresh();
                        $newBrand = $case->brand;
                        $newReceivedVia = $case->receivedViaBrand;

                        \App\Models\CaseAction::create([
                            'case_id' => $case->id,
                            'actor_id' => auth()->id(),
                            'action_type' => \App\Enums\ActionType::NoteAdded,
                            'payload' => [
                                'old_brand' => $oldBrand,
                                'new_brand' => $newBrand?->name,
                                'old_received_via' => $oldReceivedVia,
                                'new_received_via' => $newReceivedVia?->name,
                                'source' => 're-triage',
                            ],
                            'note' => "Brand re-detected (action: {$oldBrand} → " . ($newBrand?->name ?? 'unknown') . ", received via: {$oldReceivedVia} → " . ($newReceivedVia?->name ?? 'None') . ')',
                            'created_at' => now(),
                        ]);

                        $bits = [];
                        if (isset($updates['brand_id'])) {
                            $bits[] = "action brand → {$newBrand?->name}";
                        }
                        if (isset($updates['received_via_brand_id'])) {
                            $bits[] = "received via → " . ($newReceivedVia?->name ?? 'None');
                        }
                        $message .= ' — ' . implode(', ', $bits) . '.';

                        \Illuminate\Support\Facades\Log::info('Re-triage: brand reassigned', [
                            'report_id' => $reportId,
                            'case_number' => $case->case_number,
                            'old_brand' => $oldBrand,
                            'new_brand' => $newBrand?->name,
                            'old_received_via' => $oldReceivedVia,
                            'new_received_via' => $newReceivedVia?->name,
                        ]);
                    }
                }
            }

            session()->flash('success', $message);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Re-triage failed', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            session()->flash('error', 'AI triage failed: ' . $e->getMessage());
        }
    }

    protected $queryString = [
        'filterSource' => ['as' => 'source'],
        'filterType' => ['as' => 'type'],
        'filterNoise' => ['as' => 'noise'],
        'filterStatus' => ['as' => 'status'],
        'filterReporter' => ['as' => 'reporter'],
        'filterTarget' => ['as' => 'target'],
        'search' => ['as' => 'q'],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilterSource()
    {
        $this->resetPage();
    }

    public function updatingFilterType()
    {
        $this->resetPage();
    }

    public function updatingFilterNoise()
    {
        $this->resetPage();
    }

    public function updatingFilterStatus()
    {
        $this->resetPage();
    }

    public function updatingFilterReporter()
    {
        $this->resetPage();
    }

    public function updatingFilterTarget()
    {
        $this->resetPage();
    }

    /**
     * Columns the report queue can be sorted by. Whitelisted so a crafted
     * wire:click payload can't pivot the orderBy clause to an arbitrary
     * column or expression.
     */
    private const SORTABLE_COLUMNS = [
        'reported_at', 'created_at', 'case_id', 'source', 'abuse_type',
        'target_ip', 'reporter_id', 'ai_noise_score',
    ];

    /**
     * Renamed from sortBy() to dodge the property/method name collision on
     * Livewire's frontend proxy — $wire.sortBy() resolves to the string
     * property $sortBy and is not callable. The header component now wires
     * wire:click="applySort('col')".
     */
    public function applySort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    /**
     * One-click toggle for the high-priority law-enforcement queue.
     */
    public function filterLawEnforcement(): void
    {
        $le = AbuseType::LawEnforcement->value;
        $this->filterType = $this->filterType === $le ? '' : $le;
        $this->resetPage();
    }

    protected function buildQuery()
    {
        $query = AbuseReport::query();

        if ($this->filterSource) {
            $query->where('source', $this->filterSource);
        }
        if ($this->filterType) {
            $query->where('abuse_type', $this->filterType);
        }
        if ($this->filterNoise === 'noise') {
            $query->where('ai_noise_score', '>', 0.8);
        } elseif ($this->filterNoise === 'not_abuse') {
            $query->whereJsonContains('metadata->flagged_as_not_abuse', true);
        } elseif ($this->filterNoise === 'clean') {
            $query->where(function ($q) {
                $q->whereNull('ai_noise_score')->orWhere('ai_noise_score', '<=', 0.8);
            })->where(function ($q) {
                $q->whereNull('metadata->flagged_as_not_abuse')
                  ->orWhereJsonContains('metadata->flagged_as_not_abuse', false);
            });
        } elseif ($this->filterNoise === 'duplicate') {
            $query->where('is_duplicate', true);
        } elseif ($this->filterNoise === 'unlinked') {
            $query->whereNull('case_id');
        }

        if ($this->filterStatus) {
            $query->withStatus($this->filterStatus);
        }

        if ($this->filterReporter) {
            $query->where('reporter_id', $this->filterReporter);
        }

        if ($this->filterTarget) {
            $column = match ($this->filterTarget) {
                'ip' => 'target_ip',
                'domain' => 'target_domain',
                'url' => 'target_url',
                default => null,
            };
            if ($column) {
                $query->whereNotNull($column)->where($column, '!=', '');
            }
        }

        if ($this->search) {
            $needle = $this->search;
            $query->where(function ($q) use ($needle) {
                // Match the report's target identifiers, plus the raw
                // email body: IOC extraction can miss an IP, so searching
                // raw_payload keeps a report findable even when the value
                // never landed in target_ip / extra_target_ips.
                $q->where('target_ip', 'like', "%{$needle}%")
                  ->orWhere('target_domain', 'like', "%{$needle}%")
                  ->orWhere('target_url', 'like', "%{$needle}%")
                  ->orWhere('raw_payload', 'like', "%{$needle}%")
                  ->orWhereHas('case', function ($cq) use ($needle) {
                      $cq->where('case_number', 'like', "%{$needle}%");
                  });

                // External case numbers (police, CERT, DMCA, ISP) and
                // extra IPs are stored as JSON — search the serialized
                // text so any label or value matches.
                JsonColumnSearch::orWhereContains($q, 'external_case_numbers', $needle);
                JsonColumnSearch::orWhereContains($q, 'extra_target_ips', $needle);
            });
        }

        return $query;
    }

    public function render()
    {
        $reports = $this->buildQuery()
            ->with(['reporter', 'case'])
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $activeReporter = $this->filterReporter
            ? \App\Models\Reporter::find($this->filterReporter)
            : null;

        return view('livewire.admin.report-queue', [
            'reports' => $reports,
            'types' => AbuseType::cases(),
            'sources' => ['feed', 'api', 'form', 'email', 'manual'],
            'activeReporter' => $activeReporter,
            'statusOptions' => ['linked', 'duplicate', 'noise', 'no_target', 'ip_not_ours', 'unprocessed'],
        ]);
    }
}
