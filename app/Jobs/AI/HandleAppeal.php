<?php

namespace App\Jobs\AI;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use App\Services\AI\CommunicationsAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HandleAppeal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public AbuseCase $case,
        public string $appealText,
    ) {
        $this->onQueue('ai-comms');
    }

    public function handle(CommunicationsAiService $comms): void
    {
        $result = $comms->handleAppeal($this->case, $this->appealText);

        if ($result) {
            CaseAction::create([
                'case_id' => $this->case->id,
                'action_type' => ActionType::AppealReceived,
                'payload' => [
                    'appeal_text' => $this->appealText,
                    'ai_verdict' => $result,
                ],
                'note' => "AI appeal verdict: " . ($result['verdict'] ?? 'unknown'),
                'created_at' => now(),
            ]);
        }
    }
}
