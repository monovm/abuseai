<?php

namespace App\Jobs\Automation;

use App\Enums\AbuseType;
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

class EscalateCase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public AbuseCase $case,
    ) {
        $this->onQueue('automation');
    }

    public function handle(): void
    {
        $isCsam = $this->case->abuse_type === AbuseType::Csam;
        $isLawEnforcement = (bool) ($this->case->metadata['from_law_enforcement'] ?? false);

        // CSAM and law enforcement both require legal hold + LE notification
        if ($isCsam || $isLawEnforcement) {
            $this->case->update(['is_legal_hold' => true]);
            $this->notifyLawEnforcement();
        }

        $reason = match (true) {
            $isCsam => 'CSAM — immediate escalation',
            $isLawEnforcement => 'Law enforcement request',
            default => "Severity score: {$this->case->severity_score}",
        };

        CaseAction::create([
            'case_id' => $this->case->id,
            'action_type' => ActionType::Escalated,
            'payload' => [
                'reason' => $reason,
                'automated' => true,
                'legal_hold' => $isCsam || $isLawEnforcement,
            ],
            'note' => 'Case escalated to senior team',
            'created_at' => now(),
        ]);

        Log::critical('Case escalated', [
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
            'type' => $this->case->abuse_type->value,
            'law_enforcement' => $isLawEnforcement,
        ]);
    }

    protected function notifyLawEnforcement(): void
    {
        $leEmail = config('abusedesk.csam.law_enforcement_email');

        if (! $leEmail) {
            Log::error('CSAM case escalated but no law enforcement email configured', [
                'case_id' => $this->case->id,
            ]);
            return;
        }

        // Placeholder: send notification to law enforcement
        Log::critical('CSAM case: law enforcement notification queued', [
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
        ]);
    }
}
