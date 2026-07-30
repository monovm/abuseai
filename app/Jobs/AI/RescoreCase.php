<?php

namespace App\Jobs\AI;

use App\Enums\ActionType;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use App\Services\AI\IntelligenceAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RescoreCase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public AbuseCase $case,
    ) {
        $this->onQueue('ai-intel');
    }

    public function handle(IntelligenceAiService $intelligence): void
    {
        $result = $intelligence->rescoreCase($this->case);

        if (! $result || ! isset($result['adjusted_score'])) {
            Log::warning('AI rescore returned no result', ['case_id' => $this->case->id]);
            return;
        }

        $aiScore = min(max((float) $result['adjusted_score'], 0), 100);
        $algorithmicScore = (float) $this->case->severity_score;

        // Don't let the AI zero-out a case the algorithmic scorer already
        // considered actionable. AI judgement is useful as a nudge, but the
        // algorithmic score is grounded in hard facts (report count,
        // reporter reputation, recurrence) — if those say "actionable",
        // the AI shouldn't be able to bury the case to 0 because of a
        // shaky LLM disagreement. Take the higher of the two.
        $finalScore = max($algorithmicScore, $aiScore);
        $level = SeverityLevel::fromScore($finalScore);

        $this->case->update([
            'severity_score' => round($finalScore, 2),
            'severity_level' => $level,
        ]);

        CaseAction::create([
            'case_id' => $this->case->id,
            'action_type' => ActionType::AiScored,
            'payload' => array_merge($result, [
                'algorithmic_score' => $algorithmicScore,
                'ai_score' => $aiScore,
                'final_score' => $finalScore,
                'ai_overruled' => $aiScore < $algorithmicScore,
            ]),
            'note' => $result['reasoning'] ?? null,
            'created_at' => now(),
        ]);

        Log::info('AI rescore completed', [
            'case_id' => $this->case->id,
            'algorithmic_score' => $algorithmicScore,
            'ai_score' => $aiScore,
            'final_score' => $finalScore,
            'flags' => $result['flags'] ?? [],
        ]);
    }
}
