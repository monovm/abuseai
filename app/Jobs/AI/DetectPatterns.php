<?php

namespace App\Jobs\AI;

use App\Models\AbuseCase;
use App\Services\AI\IntelligenceAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectPatterns implements ShouldQueue
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
        // Find related cases by target IP, domain, or customer
        $relatedCases = AbuseCase::where('id', '!=', $this->case->id)
            ->where(function ($q) {
                if ($this->case->target_ip) {
                    $q->orWhere('target_ip', $this->case->target_ip);
                }
                if ($this->case->target_domain) {
                    $q->orWhere('target_domain', $this->case->target_domain);
                }
                if ($this->case->customer_id) {
                    $q->orWhere('customer_id', $this->case->customer_id);
                }
            })
            ->latest()
            ->take(20)
            ->get();

        if ($relatedCases->isEmpty()) {
            return;
        }

        $allCases = $relatedCases->prepend($this->case);
        $result = $intelligence->detectPatterns($allCases->all());

        if ($result) {
            $this->case->update([
                'ai_pattern_flags' => $result,
            ]);

            Log::info('Pattern detection completed', [
                'case_id' => $this->case->id,
                'pattern_type' => $result['pattern_type'] ?? 'none',
            ]);
        }
    }
}
