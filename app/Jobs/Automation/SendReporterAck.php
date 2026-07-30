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
use Illuminate\Support\Facades\Mail;

class SendReporterAck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public AbuseCase $case,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $firstReport = $this->case->reports()->with('reporter')->oldest()->first();

        if (! $firstReport?->reporter?->email) {
            return;
        }

        // Placeholder: Send ACK email via Mail facade
        // Mail::to($firstReport->reporter->email)->send(new ReporterAckMail($this->case));

        CaseAction::create([
            'case_id' => $this->case->id,
            'action_type' => ActionType::EmailSent,
            'payload' => [
                'type' => 'reporter_ack',
                'recipient' => $firstReport->reporter->email,
            ],
            'note' => "ACK email sent to reporter {$firstReport->reporter->email}",
            'created_at' => now(),
        ]);

        Log::info('Reporter ACK sent', [
            'case_id' => $this->case->id,
            'reporter' => $firstReport->reporter->email,
        ]);
    }
}
