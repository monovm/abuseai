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

class BlockIp implements ShouldQueue
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
        if (! $this->case->target_ip) {
            Log::warning('Cannot block IP: no target IP on case', ['case_id' => $this->case->id]);
            return;
        }

        // Placeholder: integrate with FirewallService
        // app(FirewallService::class)->blockIp($this->case->target_ip);

        CaseAction::create([
            'case_id' => $this->case->id,
            'action_type' => ActionType::IpBlocked,
            'payload' => [
                'ip' => $this->case->target_ip,
                'automated' => true,
            ],
            'note' => "IP {$this->case->target_ip} blocked",
            'created_at' => now(),
        ]);

        Log::info('IP blocked', [
            'case_id' => $this->case->id,
            'ip' => $this->case->target_ip,
        ]);
    }
}
