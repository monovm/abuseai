<?php

namespace App\Support;

use App\Models\AbuseCase;
use App\Models\AbuseReport;

/**
 * Collects reporter-side case / ticket / reference numbers (police case
 * numbers, CERT tickets, DMCA notice IDs, etc.) from the AI's IOC
 * extraction output and persists them on the report and its linked case
 * so agents can search by them and see them on the detail pages.
 *
 * Each entry is a {label, value} pair; merging is order-preserving and
 * deduped by lower-cased value, so re-triage passes accumulate rather
 * than overwrite.
 */
class ExternalCaseNumberCollector
{
    public static function applyToReport(AbuseReport $report, array $iocs): void
    {
        $incoming = self::normalize($iocs['external_case_numbers'] ?? []);
        if (empty($incoming)) {
            return;
        }

        $existing = is_array($report->external_case_numbers) ? $report->external_case_numbers : [];
        $merged = self::merge($existing, $incoming);

        if ($merged !== $existing) {
            $report->update(['external_case_numbers' => $merged]);
        }

        if ($report->case_id) {
            $case = AbuseCase::find($report->case_id);
            if ($case) {
                $caseExisting = is_array($case->external_case_numbers) ? $case->external_case_numbers : [];
                $caseMerged = self::merge($caseExisting, $merged);
                if ($caseMerged !== $caseExisting) {
                    $case->update(['external_case_numbers' => $caseMerged]);
                }
            }
        }
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array{label: string, value: string}>
     */
    protected static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            // Accept either {label,value} objects or bare strings.
            if (is_string($entry)) {
                $value = trim($entry);
                $label = 'Reference';
            } elseif (is_array($entry)) {
                $value = isset($entry['value']) ? trim((string) $entry['value']) : '';
                $label = isset($entry['label']) ? trim((string) $entry['label']) : 'Reference';
            } else {
                continue;
            }

            if ($value === '') {
                continue;
            }
            // Defensive: never let an "ABU-..." (our own case number)
            // sneak in here even if the model misclassifies.
            if (preg_match('/^ABU-\d{4}-\d{5}$/i', $value)) {
                continue;
            }

            $out[] = ['label' => $label !== '' ? $label : 'Reference', 'value' => $value];
        }
        return $out;
    }

    /**
     * Merge two lists, preserving order and deduping by case-insensitive
     * value. New entries from $incoming append after $existing.
     *
     * @param array<int, array{label: string, value: string}> $existing
     * @param array<int, array{label: string, value: string}> $incoming
     */
    protected static function merge(array $existing, array $incoming): array
    {
        $seen = [];
        $merged = [];

        foreach ([$existing, $incoming] as $bucket) {
            foreach ($bucket as $entry) {
                $key = strtolower($entry['value'] ?? '');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $entry;
            }
        }
        return $merged;
    }
}
