<?php

namespace App\Services\CaseEngine;

use App\Models\AbuseReport;

/**
 * When AI IOC extraction returns multiple non-reporter IPs from a single
 * report, the FIRST valid IP becomes the primary target_ip and the rest
 * are attached as extra_target_ips on the same report (and propagated to
 * its linked case). One report = one case; the extras are listed in the
 * UI alongside the primary so agents can see every IP the abuse touches
 * without the case engine fragmenting into one case per IP.
 */
class ReportSplitter
{
    /**
     * @param array<int, string> $targetIps   Candidate target IPs from parseReport()
     * @param array<int, string> $reporterIps IPs the AI marked as belonging to the reporter
     */
    public function attachExtraIps(AbuseReport $report, array $targetIps, array $reporterIps = []): void
    {
        $valid = [];
        foreach ($targetIps as $ip) {
            $ip = is_string($ip) ? trim($ip) : null;
            if (! $ip || in_array($ip, $reporterIps, true) || in_array($ip, $valid, true)) {
                continue;
            }
            $valid[] = $ip;
        }

        if (empty($valid)) {
            return;
        }

        $primary = $valid[0];
        if (empty($report->target_ip)) {
            $report->update(['target_ip' => $primary]);
        }

        // Anything after the primary, that isn't already the primary,
        // becomes an "extra" — preserve order, dedup against primary.
        $extras = [];
        foreach ($valid as $ip) {
            if ($ip === $report->target_ip) {
                continue;
            }
            if (! in_array($ip, $extras, true)) {
                $extras[] = $ip;
            }
        }

        // Merge with whatever extras the report already has so re-triage
        // and pre-screen passes accumulate IPs rather than overwriting.
        $existing = is_array($report->extra_target_ips) ? $report->extra_target_ips : [];
        $merged = array_values(array_unique(array_merge($existing, $extras)));
        // Strip the primary out of extras if it slipped in via merge.
        $merged = array_values(array_filter($merged, fn ($ip) => $ip !== $report->target_ip));

        if ($merged !== $existing) {
            $report->update(['extra_target_ips' => $merged ?: null]);
        }

        // Propagate to the parent case if the report is already linked.
        if ($report->case_id) {
            $case = $report->case ?? \App\Models\AbuseCase::find($report->case_id);
            if ($case) {
                $caseExtras = is_array($case->extra_target_ips) ? $case->extra_target_ips : [];
                $caseMerged = array_values(array_unique(array_merge($caseExtras, $merged)));
                $caseMerged = array_values(array_filter($caseMerged, fn ($ip) => $ip !== $case->target_ip));
                if ($caseMerged !== $caseExtras) {
                    $case->update(['extra_target_ips' => $caseMerged ?: null]);
                }
            }
        }
    }
}
