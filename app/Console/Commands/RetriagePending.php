<?php

namespace App\Console\Commands;

use App\Jobs\AI\TriageReport;
use App\Models\AbuseReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Re-queue TriageReport for any report whose ai_classification is still NULL.
 *
 * Designed for after-the-fact recovery when something stalls the auto-pipeline
 * (queue worker dead for hours, dispatcher silently dropping jobs, AI provider
 * misconfigured, etc.). Filters keep the blast radius bounded so an operator
 * doesn't accidentally re-classify months of data and burn the API budget.
 */
class RetriagePending extends Command
{
    protected $signature = 'abuse:retriage-pending
        {--since=7d : Only reports newer than this (e.g. 1h, 24h, 7d, 30d)}
        {--max=1000 : Hard cap on how many reports to dispatch in one run}
        {--abuse-type=* : Filter by abuse_type (repeatable)}
        {--source=* : Filter by source (feed|api|email|form|manual; repeatable)}
        {--brand= : Filter by brand_id of the parent case}
        {--dry-run : Show what would be queued without dispatching}';

    protected $description = 'Re-dispatch TriageReport for reports that never got AI-classified';

    public function handle(): int
    {
        $since = $this->parseSince($this->option('since'));
        $max = (int) $this->option('max');
        if ($max <= 0) {
            $this->error('--max must be a positive integer.');
            return self::FAILURE;
        }

        $query = AbuseReport::whereNull('ai_classification')
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc');

        if ($types = $this->option('abuse-type')) {
            $query->whereIn('abuse_type', $types);
        }
        if ($sources = $this->option('source')) {
            $query->whereIn('source', $sources);
        }
        if ($brand = $this->option('brand')) {
            $query->whereHas('case', fn ($q) => $q->where('brand_id', $brand));
        }

        $total = $query->count();
        $this->info("Found {$total} pending reports newer than {$since->format('Y-m-d H:i')}");

        if ($total === 0) {
            return self::SUCCESS;
        }

        if ($total > $max) {
            $this->warn("Capping at --max={$max}. Re-run for the rest after this batch drains.");
        }

        $reports = $query->limit($max)->get();

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'created_at', 'source', 'abuse_type', 'has_evidence'],
                $reports->map(fn ($r) => [
                    $r->id,
                    (string) $r->created_at,
                    $r->source,
                    $r->abuse_type?->value ?? '—',
                    strlen($r->evidence ?? '') > 0 ? 'yes' : 'NO',
                ])->all(),
            );
            $this->info('(dry-run) ' . $reports->count() . ' reports would have been queued');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($reports->count());
        $bar->start();

        $queued = 0;
        $failed = 0;
        foreach ($reports as $report) {
            try {
                // Bus::dispatch (not Job::dispatch) so destructor failures
                // surface — same lesson as the poller fix.
                Bus::dispatch(new TriageReport($report));
                $queued++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Skipped {$report->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Queued {$queued} jobs onto the ai-triage queue. Failed: {$failed}.");
        $this->line('Watch them drain with: redis-cli LLEN abuseai-database-queues:ai-triage');

        return self::SUCCESS;
    }

    /**
     * Accept "1h", "24h", "7d", "30d", or a strtotime-compatible expression.
     * Always returns a timestamp in the past — caller filters with >=.
     */
    protected function parseSince(string $input): \Illuminate\Support\Carbon
    {
        if (preg_match('/^(\d+)\s*([hdw])$/i', trim($input), $m)) {
            $n = (int) $m[1];
            return match (strtolower($m[2])) {
                'h' => now()->subHours($n),
                'd' => now()->subDays($n),
                'w' => now()->subWeeks($n),
            };
        }
        $ts = strtotime($input);
        if ($ts === false) {
            $this->warn("Couldn't parse --since '{$input}', defaulting to 7 days.");
            return now()->subDays(7);
        }
        return \Illuminate\Support\Carbon::createFromTimestamp($ts);
    }
}
