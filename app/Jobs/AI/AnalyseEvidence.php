<?php

namespace App\Jobs\AI;

use App\Models\AbuseCase;
use App\Services\AI\IntelligenceAiService;
use App\Support\Attachments\AttachmentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyseEvidence implements ShouldQueue
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
        // Collect all evidence from reports
        $reports = $this->case->reports()->get();
        $evidence = $reports
            ->filter(fn ($r) => ! empty($r->evidence))
            ->pluck('evidence')
            ->implode("\n\n---\n\n");

        // Pull text out of every attachment on every report so the
        // intelligence summariser sees PDFs and forwarded .eml files,
        // not just the typed description.
        $attachmentPaths = $reports
            ->flatMap(fn ($r) => $r->attachment_paths ?? [])
            ->all();
        if (! empty($attachmentPaths)) {
            $attachText = AttachmentTextExtractor::fromPaths($attachmentPaths);
            if ($attachText !== '') {
                $evidence = $attachText . "\n\n---\n\n" . $evidence;
            }
        }

        if (empty($evidence)) {
            return;
        }

        $summary = $intelligence->analyseEvidence($evidence);

        if ($summary) {
            $this->case->update(['ai_summary' => $summary]);
        }
    }
}
