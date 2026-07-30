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

class DraftReporterReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public AbuseCase $case,
        public string $replyType = 'ack',
    ) {
        $this->onQueue('ai-comms');
    }

    public function handle(CommunicationsAiService $comms): void
    {
        $draft = $comms->draftReporterReply($this->case, $this->replyType);

        if ($draft) {
            CaseAction::create([
                'case_id' => $this->case->id,
                'action_type' => ActionType::AiDrafted,
                'payload' => [
                    'draft_type' => 'reporter_reply',
                    'reply_type' => $this->replyType,
                    'draft_body' => $draft,
                ],
                'note' => "AI drafted {$this->replyType} reply for reporter",
                'created_at' => now(),
            ]);
        }
    }
}
