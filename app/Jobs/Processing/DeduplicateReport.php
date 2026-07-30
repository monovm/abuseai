<?php

namespace App\Jobs\Processing;

use App\Models\AbuseReport;
use App\Services\CaseEngine\CaseCreatorService;
use App\Services\Ingestion\DeduplicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeduplicateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public AbuseReport $report,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(DeduplicationService $dedup, CaseCreatorService $caseCreator): void
    {
        if ($dedup->isDuplicate($this->report)) {
            return;
        }

        $caseCreator->findOrCreateCase($this->report);
    }
}
