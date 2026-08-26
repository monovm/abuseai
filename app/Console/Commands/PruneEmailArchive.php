<?php

namespace App\Console\Commands;

use App\Enums\CaseStatus;
use App\Models\AbuseReport;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes aged-out raw email archives (emails/Y/m/*.eml) and, optionally,
 * the binary attachments extracted from them (evidence/Y/m/*).
 *
 * Every ingested email is persisted verbatim at intake so the AI triage
 * pass can read PDFs and forwarded .eml content. Nothing ever removed
 * them, so the archive grows without bound — tens of gigabytes on a
 * busy mailbox. The files stay referenced by abuse_reports.attachment_paths
 * forever, but they are only *read* during triage / re-triage / evidence
 * analysis and when an operator downloads one from the report detail
 * page. Once a case is closed and past the retention window, the raw
 * copy is dead weight.
 *
 * Deleting is safe by design: AttachmentController 404s on a missing
 * file and AttachmentTextExtractor skips it, so stale attachment_paths
 * entries degrade rather than break. We still refuse to touch files
 * belonging to reports on a live case unless explicitly forced.
 */
class PruneEmailArchive extends Command
{
    protected $signature = 'abuse:prune-emails
        {--days= : Delete archived files older than this many days (default: config abusedesk.retention.email_archive_days)}
        {--disk= : Disk to prune (default: the configured default disk)}
        {--include-evidence : Also prune extracted binary attachments under evidence/}
        {--force-open : Also prune files attached to reports whose case is still live}
        {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Prune aged-out raw email archives to reclaim storage';

    /** Case states that still get worked on — their evidence is never auto-pruned. */
    private const LIVE_CASE_STATUSES = [
        CaseStatus::Open,
        CaseStatus::Investigating,
        CaseStatus::Actioned,
    ];

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('abusedesk.retention.email_archive_days', 90));

        if ($days < 1) {
            $this->error('--days must be at least 1. Refusing to prune the entire archive.');

            return self::FAILURE;
        }

        $disk = $this->option('disk') ?: (config('filesystems.default') ?: 'local');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days)->startOfDay();

        $prefixes = ['emails'];
        if ($this->option('include-evidence')) {
            $prefixes[] = 'evidence';
        }

        $this->line('');
        $this->info('== Prune email archive ==');
        $this->line("  Disk:      {$disk}");
        $this->line('  Cutoff:    '.$cutoff->toDateString()." (older than {$days} days)");
        $this->line('  Prefixes:  '.implode(', ', $prefixes));
        $this->line('  Mode:      '.($dryRun ? 'DRY RUN — nothing will be deleted' : 'DELETE'));
        $this->line('');

        $protected = $this->option('force-open') ? [] : $this->protectedPaths();

        if (! $this->option('force-open')) {
            $this->line('  Protected: '.count($protected).' file(s) attached to live cases');
            $this->line('');
        }

        $totalFiles = 0;
        $totalBytes = 0;
        $skipped = 0;

        foreach ($prefixes as $prefix) {
            [$files, $bytes, $kept] = $this->prunePrefix($disk, $prefix, $cutoff, $protected, $dryRun);
            $totalFiles += $files;
            $totalBytes += $bytes;
            $skipped += $kept;
        }

        $this->line('');
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$totalFiles} file(s), ".$this->humanBytes($totalBytes).'.');

        if ($skipped > 0) {
            $this->comment("Kept {$skipped} aged file(s) still attached to live cases. Use --force-open to include them.");
        }

        if ($dryRun && $totalFiles > 0) {
            $this->line('');
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Walk one prefix month-directory by month-directory.
     *
     * The archive is laid out as <prefix>/Y/m/, which lets us decide
     * whole months by name and only stat files in the single month that
     * straddles the cutoff. On an archive with hundreds of thousands of
     * files that is the difference between one listing per month and one
     * stat syscall per file.
     *
     * @param  array<string, true>  $protected
     * @return array{0: int, 1: int, 2: int} [deleted, bytes, kept]
     */
    private function prunePrefix(string $disk, string $prefix, Carbon $cutoff, array $protected, bool $dryRun): array
    {
        $storage = Storage::disk($disk);
        $deleted = 0;
        $bytes = 0;
        $kept = 0;

        foreach ($storage->directories($prefix) as $yearDir) {
            foreach ($storage->directories($yearDir) as $monthDir) {
                $month = $this->monthFromPath($monthDir);

                // Unparseable layout (a stray directory) — fall through to
                // per-file stats rather than guessing at its age.
                if ($month !== null && $month->copy()->startOfMonth()->gte($cutoff)) {
                    continue; // Entire month is newer than the cutoff.
                }

                $wholeMonthIsOld = $month !== null && $month->copy()->endOfMonth()->lt($cutoff);

                foreach ($storage->files($monthDir) as $path) {
                    if (isset($protected[$path])) {
                        if ($wholeMonthIsOld || $this->isOlderThan($storage, $path, $cutoff)) {
                            $kept++;
                        }

                        continue;
                    }

                    if (! $wholeMonthIsOld && ! $this->isOlderThan($storage, $path, $cutoff)) {
                        continue;
                    }

                    $size = $this->sizeOf($storage, $path);

                    if (! $dryRun && ! $storage->delete($path)) {
                        $this->warn("  Failed to delete {$path}");

                        continue;
                    }

                    $deleted++;
                    $bytes += $size;
                }

                if (! $dryRun && $storage->files($monthDir) === [] && $storage->directories($monthDir) === []) {
                    $storage->deleteDirectory($monthDir);
                }
            }

            if (! $dryRun && $storage->directories($yearDir) === [] && $storage->files($yearDir) === []) {
                $storage->deleteDirectory($yearDir);
            }
        }

        if ($deleted > 0 || $kept > 0) {
            $verb = $dryRun ? 'would prune' : 'pruned';
            $this->line("  {$prefix}/: {$verb} {$deleted} file(s), ".$this->humanBytes($bytes));
        } else {
            $this->line("  {$prefix}/: nothing to prune");
        }

        return [$deleted, $bytes, $kept];
    }

    /**
     * Every attachment path belonging to a report on a live case, as a
     * lookup keyed by path. Chunked so a large archive doesn't pull the
     * whole reports table into memory.
     *
     * @return array<string, true>
     */
    private function protectedPaths(): array
    {
        $paths = [];

        AbuseReport::query()
            ->whereNotNull('attachment_paths')
            ->whereHas('case', fn ($q) => $q->whereIn('status', array_map(
                fn (CaseStatus $s) => $s->value,
                self::LIVE_CASE_STATUSES,
            )))
            ->select(['id', 'attachment_paths'])
            ->chunkById(1000, function ($reports) use (&$paths) {
                foreach ($reports as $report) {
                    foreach (($report->attachment_paths ?? []) as $path) {
                        if (is_string($path) && trim($path) !== '') {
                            $paths[$path] = true;
                        }
                    }
                }
            });

        return $paths;
    }

    /** Parse the Y/m tail of an archive directory; null when it doesn't match. */
    private function monthFromPath(string $monthDir): ?Carbon
    {
        if (! preg_match('#/(\d{4})/(\d{2})$#', '/'.trim($monthDir, '/'), $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            return null;
        }

        return Carbon::create($year, $month, 1)->startOfMonth();
    }

    private function isOlderThan(Filesystem $storage, string $path, Carbon $cutoff): bool
    {
        try {
            return Carbon::createFromTimestamp($storage->lastModified($path))->lt($cutoff);
        } catch (\Throwable) {
            // Can't establish an age — leave the file alone.
            return false;
        }
    }

    private function sizeOf(Filesystem $storage, string $path): int
    {
        try {
            return (int) $storage->size($path);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
