<?php

namespace App\Services\CaseEngine;

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use App\Events\CaseOpened;
use App\Events\CaseScored;
use App\Jobs\Automation\LinkCustomerFromInfrastructure;
use App\Models\AbuseCase;
use App\Models\AbuseReport;
use App\Models\Brand;
use App\Models\IpAddress;
use App\Services\Infrastructure\InfrastructureLookupService;
use Illuminate\Support\Facades\Log;

class CaseCreatorService
{
    public function __construct(
        protected SeverityScorerService $scorer,
    ) {}

    /**
     * Main entry point: check if the report targets our IP, then find or create a case.
     * Returns null if the reported IP is NOT in our inventory.
     */
    public function findOrCreateCase(AbuseReport $report): ?AbuseCase
    {
        $report->loadMissing('reporter');
        $isLawEnforcement = (bool) $report->reporter?->is_law_enforcement;

        // Require at least a target IP or target domain to create a case
        if (empty($report->target_ip) && empty($report->target_domain)) {
            Log::info('Report skipped: no target IP or domain', [
                'report_id' => $report->id,
            ]);

            $report->update([
                'metadata' => array_merge($report->metadata ?? [], [
                    'skipped_reason' => 'no_target_identifier',
                ]),
            ]);

            return null;
        }

        // Check if the target IP belongs to our network (must exist AND be active).
        // Law enforcement requests are exempt: LE may legitimately ask about
        // decommissioned or previously-assigned IPs, and we must preserve the record.
        if ($report->target_ip && ! $isLawEnforcement && ! $this->isOurIp($report->target_ip)) {
            // findByIp also falls through to matching blocks, so a report on
            // 2001:db8::abc will find our /64 block if we own one.
            $ipRecord = IpAddress::findByIp($report->target_ip);
            $reason = $ipRecord
                ? "ip_exists_but_not_active (status: {$ipRecord->status})"
                : 'ip_not_in_inventory';

            Log::info("Report skipped: {$reason}", [
                'report_id' => $report->id,
                'target_ip' => $report->target_ip,
                'ip_exists' => (bool) $ipRecord,
                'ip_status' => $ipRecord?->status,
            ]);

            $report->update([
                'metadata' => array_merge($report->metadata ?? [], [
                    'skipped_reason' => $reason,
                ]),
            ]);

            return null;
        }

        $lockKey = $this->buildLockKey($report);
        $lock = cache()->lock($lockKey, 10);

        return $lock->block(5, function () use ($report) {
            $existingCase = $this->findExistingCase($report);

            if ($existingCase) {
                return $this->linkReportToCase($report, $existingCase);
            }

            return $this->createNewCase($report);
        });
    }

    /**
     * Check if an IP is in our inventory.
     */
    protected function isOurIp(string $ip): bool
    {
        return IpAddress::isOurs($ip);
    }

    /**
     * Auto-detect customer from our IP inventory (falls through to block membership).
     */
    protected function resolveCustomerFromIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        return IpAddress::findByIp($ip)?->customer_id;
    }

    protected function findExistingCase(AbuseReport $report): ?AbuseCase
    {
        $query = AbuseCase::whereIn('status', [CaseStatus::Open, CaseStatus::Investigating])
            ->where('abuse_type', $report->abuse_type);

        if ($report->target_ip) {
            $query->where('target_ip', $report->target_ip);
        } elseif ($report->target_domain) {
            $query->where('target_domain', $report->target_domain);
        } else {
            return null;
        }

        return $query->first();
    }

    protected function linkReportToCase(AbuseReport $report, AbuseCase $case): AbuseCase
    {
        $report->update(['case_id' => $case->id]);

        $case->increment('report_count');
        $updates = ['last_seen_at' => now()];

        // Track the earliest abuse-occurrence time across every linked
        // report — that's the answer to "when did this start?".
        if ($report->abuse_occurred_at && (
            ! $case->abuse_occurred_at
            || $report->abuse_occurred_at->lt($case->abuse_occurred_at)
        )) {
            $updates['abuse_occurred_at'] = $report->abuse_occurred_at;
        }

        // Merge any extra IPs the linked report carries into the case's
        // extra_target_ips list, dedup against the case's primary.
        $reportExtras = is_array($report->extra_target_ips) ? $report->extra_target_ips : [];
        if (! empty($reportExtras)) {
            $caseExtras = is_array($case->extra_target_ips) ? $case->extra_target_ips : [];
            $merged = array_values(array_unique(array_merge($caseExtras, $reportExtras)));
            $merged = array_values(array_filter($merged, fn ($ip) => $ip !== $case->target_ip));
            if ($merged !== $caseExtras) {
                $updates['extra_target_ips'] = $merged ?: null;
            }
        }

        // Merge external case / ticket numbers (police, CERT, DMCA, etc.)
        // from the linked report into the case so all references are
        // searchable in one place.
        $reportRefs = is_array($report->external_case_numbers) ? $report->external_case_numbers : [];
        if (! empty($reportRefs)) {
            $caseRefs = is_array($case->external_case_numbers) ? $case->external_case_numbers : [];
            $seen = [];
            $mergedRefs = [];
            foreach ([$caseRefs, $reportRefs] as $bucket) {
                foreach ($bucket as $entry) {
                    $key = strtolower($entry['value'] ?? '');
                    if ($key === '' || isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $mergedRefs[] = $entry;
                }
            }
            if ($mergedRefs !== $caseRefs) {
                $updates['external_case_numbers'] = $mergedRefs ?: null;
            }
        }

        $existingMeta = $case->metadata ?? [];
        $additions = [];

        // Propagate LE flag if a follow-up LE report attaches to a previously
        // non-LE case (e.g. original report from a feed, FBI follow-up email).
        if ($report->reporter?->is_law_enforcement && empty($existingMeta['from_law_enforcement'] ?? null)) {
            $additions['from_law_enforcement'] = true;
            $additions['law_enforcement_reporter_id'] = $report->reporter_id;
        }

        // Same idea for a trusted reporter attaching to a previously untrusted case.
        if ($report->reporter?->is_trusted && empty($existingMeta['from_trusted_reporter'] ?? null)) {
            $additions['from_trusted_reporter'] = true;
        }

        // Cache provider resolution on the case so rules can match without
        // hitting external APIs on every evaluation.
        if (empty($existingMeta['whmcs_service_id'] ?? null)) {
            $additions = array_merge($additions, $this->lookupWhmcsMetadata($report, $case->brand));
        }

        if (! empty($additions)) {
            $updates['metadata'] = array_merge($existingMeta, $additions);
        }
        $case->update($updates);

        $this->scorer->scoreAndUpdate($case);

        CaseScored::dispatch($case);

        return $case->fresh();
    }

    /**
     * Metadata entries derived from the reporter behind this report.
     */
    protected function buildReporterMetadata(AbuseReport $report, bool $isLawEnforcement, bool $isTrusted): array
    {
        $meta = [];
        if ($isLawEnforcement) {
            $meta['from_law_enforcement'] = true;
            $meta['law_enforcement_reporter_id'] = $report->reporter_id;
        }
        if ($isTrusted) {
            $meta['from_trusted_reporter'] = true;
        }
        return $meta;
    }

    /**
     * Best-effort provider lookup; populates whmcs_service_id /
     * whmcs_client_id / whmcs_brand / whmcs_hostname on the case metadata
     * so automation rules can match without re-hitting external APIs.
     * Field names are kept whmcs_*-prefixed for backwards compatibility
     * with existing rule conditions — the actual provider that produced
     * the values is recorded under the `provider` key.
     *
     * Now provider-aware: takes a Brand so we can route the lookup to the
     * right integration. Graceful on failure.
     */
    protected function lookupWhmcsMetadata(AbuseReport $report, ?Brand $brand): array
    {
        if (! $report->target_ip || ! $brand) {
            return [];
        }

        try {
            $record = app(InfrastructureLookupService::class)->lookup($brand, $report->target_ip);
        } catch (\Throwable $e) {
            Log::info('Provider lookup for case metadata skipped', [
                'ip' => $report->target_ip,
                'brand' => $brand->name,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (! $record) {
            return [];
        }

        return array_filter([
            'whmcs_service_id' => $record->serviceId,
            'whmcs_client_id' => $record->clientId,
            'whmcs_brand' => $brand->name,
            'whmcs_hostname' => $record->hostname,
            'provider' => $record->rawProvider,
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function createNewCase(AbuseReport $report): AbuseCase
    {
        $customerId = $this->resolveCustomerFromIp($report->target_ip);
        $brandId = $this->resolveBrandFromReport($report);
        $receivedViaBrandId = $this->resolveReceivedViaBrandFromReport($report);
        $brand = $brandId ? Brand::find($brandId) : null;
        $isLawEnforcement = (bool) $report->reporter?->is_law_enforcement;
        $isTrusted = (bool) $report->reporter?->is_trusted;

        $metadata = $this->buildReporterMetadata($report, $isLawEnforcement, $isTrusted);
        $metadata = array_merge($metadata, $this->lookupWhmcsMetadata($report, $brand));
        if (empty($metadata)) {
            $metadata = null;
        }

        // Retry case creation in case of duplicate case_number (race condition)
        $maxAttempts = 3;
        $case = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $case = AbuseCase::create([
                    'case_number' => AbuseCase::generateCaseNumber(),
                    'status' => CaseStatus::Open,
                    'abuse_type' => $report->abuse_type,
                    'severity_score' => 0,
                    'severity_level' => SeverityLevel::Low,
                    'target_ip' => $report->target_ip,
                    'target_domain' => $report->target_domain,
                    'customer_id' => $customerId,
                    'brand_id' => $brandId,
                    'received_via_brand_id' => $receivedViaBrandId,
                    'report_count' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'abuse_occurred_at' => $report->abuse_occurred_at,
                    'extra_target_ips' => is_array($report->extra_target_ips) && ! empty($report->extra_target_ips)
                        ? array_values(array_filter($report->extra_target_ips, fn ($ip) => $ip !== $report->target_ip))
                        : null,
                    'external_case_numbers' => is_array($report->external_case_numbers) && ! empty($report->external_case_numbers)
                        ? $report->external_case_numbers
                        : null,
                    'sla_deadline' => $this->calculateSlaDeadline(SeverityLevel::Low),
                    'metadata' => $metadata,
                ]);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= $maxAttempts || ! str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
                Log::warning('Case number collision, retrying', ['attempt' => $attempt]);
            }
        }

        $report->update(['case_id' => $case->id]);

        $this->scorer->scoreAndUpdate($case);

        CaseOpened::dispatch($case);
        CaseScored::dispatch($case);

        // If the IP wasn't pre-mapped to a customer in our inventory, try the
        // live Virtualizor + WHMCS lookup asynchronously so the case engine
        // doesn't block on a billing-system round-trip.
        if (! $case->customer_id && $case->target_ip) {
            LinkCustomerFromInfrastructure::dispatch($case);
        }

        return $case->fresh();
    }

    /**
     * Detect brand from report.
     * Priority:
     *   1. metadata.brand_id if caller already resolved it (mailbox poller,
     *      manual import).
     *   2. The email's recipient address — if the report came in on
     *      abuse@brand-b.example, that's Brand B. This beats Virtualizor
     *      guessing when the target IP is unknown/foreign (e.g. forwarded
     *      CERT notices about a third-party IP).
     *   3. Infrastructure lookup: IP → Virtualizor hostname → brand pattern.
     *   4. Default brand.
     */
    public function resolveBrandFromReport(AbuseReport $report, bool $ignoreExistingCase = false): ?string
    {
        // 1. Definitive hostname_pattern match from provider lookup.
        // Hostname comes from authoritative infrastructure lookup, so when a
        // pattern matches it beats every other signal (metadata.brand_id from
        // the mailbox poller, recipient address, etc.) — those all only
        // describe where the mail landed, while hostname_pattern describes
        // who actually owns the IP.
        if ($report->target_ip) {
            $patternMatch = Brand::findByHostnamePatternForIp($report->target_ip);
            if ($patternMatch) {
                return $patternMatch->id;
            }
        }

        // 2. Explicit metadata.brand_id (caller hint from mailbox poller,
        //    manual import, API).
        $metaBrandId = $report->metadata['brand_id'] ?? null;
        if ($metaBrandId && Brand::find($metaBrandId)) {
            return $metaBrandId;
        }

        // 3. Recipient address on the email
        $recipientBrand = $this->matchBrandByRecipient($report);
        if ($recipientBrand) {
            return $recipientBrand->id;
        }

        // 4. Provider lookup fallback: provider answered for this IP but no
        // hostname pattern matched — return the brand whose provider produced
        // the hit. Falls through to default brand if nothing matches.
        if ($report->target_ip) {
            $matched = Brand::findByIp($report->target_ip, $ignoreExistingCase);
            if ($matched) {
                return $matched->id;
            }

            $default = Brand::getDefault();
            if ($default) {
                return $default->id;
            }
        }

        return null;
    }

    /**
     * Brand the report was received via (recipient inbox / from-address). Used
     * as the default reply-from identity, independent of the action brand.
     * Returns null when no recipient signal is available (e.g. API/webhook
     * report) — caller falls back to the action brand for replies.
     */
    public function resolveReceivedViaBrandFromReport(AbuseReport $report): ?string
    {
        $metaBrandId = $report->metadata['brand_id'] ?? null;
        if ($metaBrandId && Brand::find($metaBrandId)) {
            return $metaBrandId;
        }

        return $this->matchBrandByRecipient($report)?->id;
    }

    /**
     * Match by recipient address: scan any "received-at" header we have (To,
     * Delivered-To, X-Original-To) plus the registered inbox of the
     * EmailConnection the report arrived on, and find a brand whose
     * from_email / reply_to_email / inbox matches.
     */
    protected function matchBrandByRecipient(AbuseReport $report): ?Brand
    {
        $candidates = $this->extractRecipientAddresses($report);
        if (empty($candidates)) {
            return null;
        }

        foreach (Brand::active()->get() as $brand) {
            $brandAddrs = array_filter([
                strtolower((string) $brand->from_email),
                strtolower((string) $brand->reply_to_email),
            ]);

            foreach ($candidates as $addr) {
                if ($addr && in_array($addr, $brandAddrs, true)) {
                    return $brand;
                }
            }
        }

        // Still nothing — try matching against registered EmailConnection inboxes
        // (the poller sets metadata.received_via_connection_id, but this covers
        // webhook/API paths too).
        foreach ($candidates as $addr) {
            $conn = \App\Models\EmailConnection::whereRaw('LOWER(username) = ?', [$addr])->first();
            if ($conn && $conn->brand_id && ($brand = Brand::find($conn->brand_id))) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * Pull every plausible recipient address out of a report. Email headers
     * may store multiple addresses in one field — we split and lowercase.
     *
     * @return string[] lowercase email addresses
     */
    protected function extractRecipientAddresses(AbuseReport $report): array
    {
        $headers = $report->headers ?? [];
        $raw = [];

        foreach (['to', 'delivered_to', 'x_original_to', 'envelope_to'] as $key) {
            if (! empty($headers[$key])) {
                $raw[] = $headers[$key];
            }
        }

        $out = [];
        foreach ($raw as $block) {
            // Pull addresses out of "Name <user@host>, other@host" style values.
            if (preg_match_all('/<([^>]+)>|([\w\.\-\+]+@[\w\.\-]+)/', (string) $block, $m)) {
                foreach ($m[0] as $i => $_match) {
                    $addr = ! empty($m[1][$i]) ? $m[1][$i] : ($m[2][$i] ?? '');
                    $addr = strtolower(trim($addr, "<>\"' \t"));
                    if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        $out[] = $addr;
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    protected function buildLockKey(AbuseReport $report): string
    {
        $target = $report->target_ip ?? $report->target_domain ?? 'unknown';
        $type = $report->abuse_type instanceof AbuseType
            ? $report->abuse_type->value
            : $report->abuse_type;

        return "case_lock:{$target}:{$type}";
    }

    protected function calculateSlaDeadline(SeverityLevel $level): \Carbon\Carbon
    {
        $hours = config("abusedesk.sla.{$level->value}", 72);

        return now()->addHours($hours);
    }
}
