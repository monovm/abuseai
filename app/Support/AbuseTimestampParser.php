<?php

namespace App\Support;

use App\Models\AbuseCase;
use App\Models\AbuseReport;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Picks the most useful "when did the abuse happen" timestamp from a
 * list of strings the AI extracted out of email body / attachment text.
 *
 * Filters out garbage, future-dated values (which are almost always
 * parse errors — abuse is by definition something that already
 * happened), and anything older than five years (typically email
 * signatures, copyright dates, or PDF metadata leaking into the parse).
 *
 * Returns the EARLIEST surviving timestamp. Earliest is what an
 * incident responder cares about: when did this start.
 */
class AbuseTimestampParser
{
    /**
     * @param array<int, mixed> $candidates Raw timestamp strings from the AI.
     */
    public static function pickEarliest(array $candidates): ?CarbonInterface
    {
        $now = Carbon::now();
        $oldestAllowed = $now->copy()->subYears(5);

        $parsed = [];

        foreach ($candidates as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $cleaned = trim($raw);
            // Drop bare year ranges like "2020-2024" — usually copyright lines.
            if (preg_match('/^\d{4}\s*[-–]\s*\d{4}$/', $cleaned)) {
                continue;
            }

            try {
                $dt = Carbon::parse($cleaned);
            } catch (\Throwable) {
                continue;
            }

            // Skip impossible / future / ancient values.
            if ($dt->isFuture() && $dt->diffInMinutes($now, true) > 60) {
                continue;
            }
            if ($dt->lessThan($oldestAllowed)) {
                continue;
            }

            $parsed[] = $dt;
        }

        if (empty($parsed)) {
            return null;
        }

        usort($parsed, fn ($a, $b) => $a <=> $b);
        return $parsed[0];
    }

    /**
     * Set abuse_occurred_at on a report from the AI's IOC timestamps,
     * and propagate to the parent case if this is earlier than any
     * timestamp already recorded there. Idempotent: won't overwrite
     * a value an admin or earlier triage pass already set.
     */
    public static function applyToReport(AbuseReport $report, array $iocs): void
    {
        if ($report->abuse_occurred_at) {
            return;
        }

        $occurredAt = self::pickEarliest($iocs['timestamps'] ?? []);
        if (! $occurredAt) {
            return;
        }

        $report->update(['abuse_occurred_at' => $occurredAt]);

        if ($report->case_id) {
            $case = AbuseCase::find($report->case_id);
            if ($case && (! $case->abuse_occurred_at || $occurredAt->lt($case->abuse_occurred_at))) {
                $case->update(['abuse_occurred_at' => $occurredAt]);
            }
        }
    }
}
