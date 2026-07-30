<?php

namespace App\Services\CaseEngine;

use App\Enums\AbuseType;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;

class SeverityScorerService
{
    public function calculate(AbuseCase $case): float
    {
        // CSAM is always critical — skip scoring
        if ($case->abuse_type === AbuseType::Csam) {
            return 100.0;
        }

        $baseWeight = $case->abuse_type->baseWeight();

        $reportFactor = log10($case->report_count + 1) * 10;

        $avgReputation = $case->reports()
            ->join('reporters', 'abuse_reports.reporter_id', '=', 'reporters.id')
            ->avg('reporters.reputation_score') ?? 50;
        $reputationMultiplier = $avgReputation / 100;

        $recurrenceFactor = 1.0;
        if ($case->customer_id) {
            $priorCaseCount = AbuseCase::where('customer_id', $case->customer_id)
                ->where('id', '!=', $case->id)
                ->count();
            $increment = config('abusedesk.scoring.recurrence_increment', 0.25);
            $recurrenceFactor += $priorCaseCount * $increment;
        }

        $score = $baseWeight * $reportFactor * $reputationMultiplier * $recurrenceFactor;

        return min($score, 100.0);
    }

    public function scoreAndUpdate(AbuseCase $case): AbuseCase
    {
        $score = $this->calculate($case);
        $level = SeverityLevel::fromScore($score);

        $case->update([
            'severity_score' => round($score, 2),
            'severity_level' => $level,
        ]);

        return $case;
    }
}
