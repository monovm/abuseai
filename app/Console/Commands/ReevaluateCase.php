<?php

namespace App\Console\Commands;

use App\Events\CaseScored;
use App\Models\AbuseCase;
use Illuminate\Console\Command;

class ReevaluateCase extends Command
{
    protected $signature = 'case:reevaluate
        {case_number : Case number, e.g. ABU-2026-00036}
        {--trust-reporter : Mark metadata.from_trusted_reporter = true before dispatching}
        {--le-reporter : Mark metadata.from_law_enforcement = true before dispatching}
        {--dry-run : Print what would change without dispatching}';

    protected $description = 'Re-fire automation rules on an existing case by dispatching CaseScored';

    public function handle(): int
    {
        $case = AbuseCase::where('case_number', $this->argument('case_number'))->first();
        if (! $case) {
            $this->error("Case {$this->argument('case_number')} not found.");
            return self::FAILURE;
        }

        $meta = $case->metadata ?? [];
        $additions = [];

        if ($this->option('trust-reporter') && empty($meta['from_trusted_reporter'])) {
            $additions['from_trusted_reporter'] = true;
        }
        if ($this->option('le-reporter') && empty($meta['from_law_enforcement'])) {
            $additions['from_law_enforcement'] = true;
        }

        $this->line('');
        $this->info("Case {$case->case_number} — current metadata:");
        $this->line('  ' . json_encode([
            'from_trusted_reporter' => $meta['from_trusted_reporter'] ?? null,
            'from_law_enforcement' => $meta['from_law_enforcement'] ?? null,
            'whmcs_service_id' => $meta['whmcs_service_id'] ?? null,
            'whmcs_client_id' => $meta['whmcs_client_id'] ?? null,
            'whmcs_brand' => $meta['whmcs_brand'] ?? null,
        ], JSON_PRETTY_PRINT));

        if ($additions) {
            $this->warn('Will add: ' . json_encode($additions));
        }

        if ($this->option('dry-run')) {
            $this->comment('(dry-run — no changes made, no event dispatched)');
            return self::SUCCESS;
        }

        if ($additions) {
            $case->update(['metadata' => array_merge($meta, $additions)]);
            $case->refresh();
        }

        CaseScored::dispatch($case);
        $this->info('CaseScored dispatched; HandleCaseScored listener + automation rules will run on the next queue tick.');
        $this->line('  Tail logs: tail -f storage/logs/laravel.log');

        return self::SUCCESS;
    }
}
