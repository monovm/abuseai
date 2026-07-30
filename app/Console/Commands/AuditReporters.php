<?php

namespace App\Console\Commands;

use App\Models\AbuseReport;
use App\Models\CaseAction;
use App\Models\Reporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditReporters extends Command
{
    protected $signature = 'reporters:audit
        {--fix : Apply suggested merges (reassign reports + sum counters + drop duplicate rows)}
        {--email= : Audit a single email (case-insensitive substring match)}';

    protected $description = 'Find reporter integrity issues: duplicate rows, stale counters, orphan reports';

    public function handle(): int
    {
        $emailFilter = $this->option('email') ? strtolower(trim($this->option('email'))) : null;
        $fix = (bool) $this->option('fix');

        $this->line('');
        $this->info('== Reporters audit ==');
        $this->line('');

        $duplicateGroups = $this->findDuplicateGroups($emailFilter);
        $this->reportDuplicates($duplicateGroups);

        if ($fix && $duplicateGroups->isNotEmpty()) {
            $this->line('');
            $this->info('Merging duplicates…');
            $merged = $this->mergeDuplicates($duplicateGroups);
            $this->info("Merged {$merged['groups']} group(s); reassigned {$merged['reports']} report(s); deleted {$merged['deleted']} duplicate row(s).");
        }

        $this->line('');
        $stale = $this->findStaleCounters($emailFilter);
        $this->reportStaleCounters($stale);

        $this->line('');
        $orphans = $this->findOrphanReports();
        $this->reportOrphans($orphans);

        if (! $fix && $duplicateGroups->isNotEmpty()) {
            $this->line('');
            $this->comment('Re-run with --fix to apply the merge plan above.');
        }

        return self::SUCCESS;
    }

    /**
     * Reporters whose normalized email (lowercased + trimmed) collides with another row.
     * Returns a Collection keyed by normalized email, each entry => Collection<Reporter>.
     */
    protected function findDuplicateGroups(?string $emailFilter): \Illuminate\Support\Collection
    {
        $query = Reporter::query()->withCount('reports');
        if ($emailFilter) {
            $query->whereRaw('LOWER(email) LIKE ?', ['%' . $emailFilter . '%']);
        }

        return $query->get()
            ->groupBy(fn ($r) => strtolower(trim((string) $r->email)))
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->sortByDesc(fn ($r) => $r->reports_count + (int) $r->report_count)->values());
    }

    protected function reportDuplicates(\Illuminate\Support\Collection $groups): void
    {
        if ($groups->isEmpty()) {
            $this->info('Duplicate reporter rows: none ✓');
            return;
        }

        $this->warn("Duplicate reporter rows ({$groups->count()} group(s)):");

        foreach ($groups as $email => $rows) {
            $this->line("  {$email}");
            foreach ($rows as $i => $r) {
                $marker = $i === 0 ? 'KEEP' : 'merge ↑';
                $this->line(sprintf(
                    '    [%s] id=%s  created=%s  reports=%d  counter=%d  LE=%s  trusted=%s  blocked=%s',
                    $marker,
                    substr($r->id, 0, 8),
                    $r->created_at?->format('Y-m-d') ?? '-',
                    (int) $r->reports_count,
                    (int) $r->report_count,
                    $r->is_law_enforcement ? 'yes' : 'no',
                    $r->is_trusted ? 'yes' : 'no',
                    $r->is_blocked ? 'yes' : 'no',
                ));
            }
        }
    }

    protected function mergeDuplicates(\Illuminate\Support\Collection $groups): array
    {
        $totals = ['groups' => 0, 'reports' => 0, 'deleted' => 0];

        foreach ($groups as $email => $rows) {
            $canonical = $rows->first();
            $duplicates = $rows->slice(1);

            DB::transaction(function () use ($canonical, $duplicates, $email, &$totals) {
                $duplicateIds = $duplicates->pluck('id')->all();

                // Reassign reports.
                $reassigned = AbuseReport::whereIn('reporter_id', $duplicateIds)
                    ->update(['reporter_id' => $canonical->id]);

                // Sum counters (preserves historical signal used by the reputation service).
                $summedCounter = (int) $canonical->report_count
                    + (int) $duplicates->sum('report_count');

                // OR booleans — most-permissive flag wins, but blocked stays sticky.
                $canonical->forceFill([
                    'report_count' => $summedCounter,
                    'is_trusted' => $canonical->is_trusted || $duplicates->contains(fn ($d) => $d->is_trusted),
                    'is_law_enforcement' => $canonical->is_law_enforcement || $duplicates->contains(fn ($d) => $d->is_law_enforcement),
                    'is_blocked' => $canonical->is_blocked || $duplicates->contains(fn ($d) => $d->is_blocked),
                    // Normalize the canonical row's email so future firstOrCreate calls land here.
                    'email' => strtolower(trim($canonical->email)),
                ])->save();

                // Drop duplicates.
                Reporter::whereIn('id', $duplicateIds)->delete();

                $totals['groups']++;
                $totals['reports'] += (int) $reassigned;
                $totals['deleted'] += count($duplicateIds);
            });
        }

        return $totals;
    }

    /**
     * Reporters whose stored counter exceeds the live abuse_reports row count.
     * Returns rows ordered by largest gap.
     */
    protected function findStaleCounters(?string $emailFilter): \Illuminate\Support\Collection
    {
        $query = Reporter::query()->withCount('reports');
        if ($emailFilter) {
            $query->whereRaw('LOWER(email) LIKE ?', ['%' . $emailFilter . '%']);
        }

        return $query->get()
            ->filter(fn ($r) => (int) $r->report_count > (int) $r->reports_count)
            ->sortByDesc(fn ($r) => (int) $r->report_count - (int) $r->reports_count)
            ->values();
    }

    protected function reportStaleCounters(\Illuminate\Support\Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->info('Stale counters: none ✓');
            return;
        }

        $this->warn("Stale counters ({$rows->count()} reporter(s) where stored > live):");
        $this->line('  email                                  counter   live   gap   note');
        foreach ($rows->take(20) as $r) {
            $gap = (int) $r->report_count - (int) $r->reports_count;
            $note = $r->reports_count === 0 ? '(zero live rows — likely pruned or duplicate)' : '';
            $this->line(sprintf(
                '  %-38s  %7d  %5d  %5d  %s',
                substr((string) $r->email, 0, 38),
                (int) $r->report_count,
                (int) $r->reports_count,
                $gap,
                $note,
            ));
        }
        if ($rows->count() > 20) {
            $this->line('  … (' . ($rows->count() - 20) . ' more)');
        }
        $this->line('  The stored counter is preserved on purpose — it feeds the');
        $this->line('  reputation service\'s "have we seen this reporter often enough" check.');
    }

    /**
     * Reports whose reporter_id is null or points to a row that no longer exists.
     */
    protected function findOrphanReports(): array
    {
        $nullCount = AbuseReport::whereNull('reporter_id')->count();
        $danglingCount = AbuseReport::whereNotNull('reporter_id')
            ->whereNotIn('reporter_id', Reporter::query()->select('id'))
            ->count();
        return ['null' => $nullCount, 'dangling' => $danglingCount];
    }

    protected function reportOrphans(array $orphans): void
    {
        if ($orphans['null'] === 0 && $orphans['dangling'] === 0) {
            $this->info('Orphan reports: none ✓');
            return;
        }
        $this->warn('Orphan reports:');
        $this->line("  - reporter_id IS NULL: {$orphans['null']}");
        $this->line("  - reporter_id points to a deleted reporter: {$orphans['dangling']}");
    }
}
