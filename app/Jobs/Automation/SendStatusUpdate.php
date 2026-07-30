<?php

namespace App\Jobs\Automation;

use App\Enums\ActionType;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendStatusUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public AbuseCase $case,
        public array $params = [],
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $channel = $this->params['channel'] ?? 'email';

        // Placeholder: Integrate with Slack, PagerDuty, email
        // match ($channel) {
        //     'slack' => NotificationService::slack($this->case),
        //     'pagerduty' => NotificationService::pagerduty($this->case),
        //     default => NotificationService::email($this->case),
        // };

        CaseAction::create([
            'case_id' => $this->case->id,
            'action_type' => ActionType::EmailSent,
            'payload' => [
                'type' => 'status_update',
                'channel' => $channel,
                'params' => $this->params,
            ],
            'note' => "Status update sent via {$channel}",
            'created_at' => now(),
        ]);

        Log::info('Status update sent', [
            'case_id' => $this->case->id,
            'channel' => $channel,
        ]);
    }
}
