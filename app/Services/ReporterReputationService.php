<?php

namespace App\Services;

use App\Models\Reporter;
use Illuminate\Support\Facades\Log;

class ReporterReputationService
{
    /**
     * Adjust reporter reputation based on report outcomes.
     * Called after AI triage or manual review determines report quality.
     */
    public function adjustForReport(Reporter $reporter, string $outcome, float $confidence = 1.0): void
    {
        $currentScore = (float) $reporter->reputation_score;
        $adjustment = 0;

        switch ($outcome) {
            case 'valid_abuse':
                // Good report — reward
                $adjustment = 2.0 * $confidence;
                break;
            case 'not_abuse':
                // Sent non-abuse to abuse mailbox — minor penalty
                $adjustment = -3.0 * $confidence;
                break;
            case 'noise':
                // Low-quality/automated noise — penalty
                $adjustment = -5.0 * $confidence;
                break;
            case 'duplicate':
                // Duplicate — slight penalty (could be automated flood)
                $adjustment = -1.0;
                break;
            case 'false_positive':
                // Manually confirmed false positive — significant penalty
                $adjustment = -10.0;
                break;
            case 'critical_valid':
                // Reported genuine critical abuse (CSAM, etc.) — big reward
                $adjustment = 10.0;
                break;
        }

        $newScore = max(0, min(100, $currentScore + $adjustment));

        if ($newScore !== $currentScore) {
            $reporter->update(['reputation_score' => $newScore]);

            // Auto-block reporters with very low reputation
            if ($newScore <= 5 && $reporter->report_count >= 10 && ! $reporter->is_trusted) {
                $reporter->update(['is_blocked' => true]);
                Log::warning('Reporter auto-blocked due to low reputation', [
                    'reporter_id' => $reporter->id,
                    'email' => $reporter->email,
                    'reputation' => $newScore,
                    'report_count' => $reporter->report_count,
                ]);
            }
        }
    }

    /**
     * Batch recalculate reputation for a reporter based on their report history.
     */
    public function recalculate(Reporter $reporter): float
    {
        $reports = $reporter->reports()->latest()->take(100)->get();

        if ($reports->isEmpty()) {
            return 50.0; // Default score
        }

        $score = 50.0; // Start from neutral

        foreach ($reports as $report) {
            $isNotAbuse = $report->metadata['flagged_as_not_abuse'] ?? false;
            $noiseScore = (float) ($report->ai_noise_score ?? 0);
            $isDuplicate = $report->is_duplicate;
            $hasCase = ! empty($report->case_id);

            if ($isNotAbuse) {
                $score -= 3.0;
            } elseif ($noiseScore > 0.8) {
                $score -= 2.0;
            } elseif ($isDuplicate) {
                $score -= 0.5;
            } elseif ($hasCase) {
                $score += 1.5;
            }
        }

        $score = max(0, min(100, $score));

        $reporter->update(['reputation_score' => $score]);

        return $score;
    }
}
