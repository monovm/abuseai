<?php

namespace App\Services\CaseEngine;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaseMergeService
{
    /**
     * Merge source case into target case.
     * All reports, actions from source are moved to target.
     * Source case is closed with a merge note.
     */
    public function merge(AbuseCase $target, AbuseCase $source, ?int $actorId = null): AbuseCase
    {
        return DB::transaction(function () use ($target, $source, $actorId) {
            // Move all reports from source to target
            $movedReports = $source->reports()->update(['case_id' => $target->id]);

            // Update report count
            $target->update([
                'report_count' => $target->reports()->count(),
                'last_seen_at' => max($target->last_seen_at, $source->last_seen_at),
            ]);

            // Log merge action on target
            CaseAction::create([
                'case_id' => $target->id,
                'actor_id' => $actorId,
                'action_type' => ActionType::NoteAdded,
                'payload' => [
                    'type' => 'case_merged',
                    'merged_from' => $source->case_number,
                    'merged_from_id' => $source->id,
                    'reports_moved' => $movedReports,
                ],
                'note' => "Merged case {$source->case_number} into this case ({$movedReports} reports moved)",
                'created_at' => now(),
            ]);

            // Close source case
            $source->update([
                'status' => 'closed',
                'closed_at' => now(),
                'metadata' => array_merge($source->metadata ?? [], [
                    'merged_into' => $target->case_number,
                    'merged_into_id' => $target->id,
                    'merged_at' => now()->toIso8601String(),
                ]),
            ]);

            CaseAction::create([
                'case_id' => $source->id,
                'actor_id' => $actorId,
                'action_type' => ActionType::Closed,
                'payload' => [
                    'type' => 'merged',
                    'merged_into' => $target->case_number,
                ],
                'note' => "Case closed — merged into {$target->case_number}",
                'created_at' => now(),
            ]);

            // Re-score target case
            app(SeverityScorerService::class)->scoreAndUpdate($target);

            Log::info('Cases merged', [
                'target' => $target->case_number,
                'source' => $source->case_number,
                'reports_moved' => $movedReports,
            ]);

            return $target->fresh();
        });
    }
}
