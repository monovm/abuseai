<?php

namespace App\Services\CaseEngine;

use App\Models\AbuseCase;
use App\Models\AbuseReport;

class AggregatorService
{
    public function __construct(
        protected SeverityScorerService $scorer,
    ) {}

    public function aggregate(AbuseCase $case): AbuseCase
    {
        // Recalculate report count from actual linked reports
        $reportCount = $case->reports()->count();
        $lastReport = $case->reports()->latest('reported_at')->first();

        $case->update([
            'report_count' => $reportCount,
            'last_seen_at' => $lastReport?->reported_at ?? $case->last_seen_at,
        ]);

        // Re-score the case
        $this->scorer->scoreAndUpdate($case);

        return $case->fresh();
    }

    public function mergeIntoCase(AbuseCase $target, AbuseCase $source): AbuseCase
    {
        // Move all reports from source to target
        AbuseReport::where('case_id', $source->id)
            ->update(['case_id' => $target->id]);

        // Close the source case
        $source->update([
            'status' => 'closed',
            'closed_at' => now(),
            'metadata' => array_merge($source->metadata ?? [], [
                'merged_into' => $target->case_number,
            ]),
        ]);

        return $this->aggregate($target);
    }
}
