<?php

namespace App\Services\Ingestion;

use App\Models\AbuseReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Dedup engine with multi-level fingerprinting and configurable time window.
 * Uses Redis for fast lookups with automatic expiry.
 */
class DeduplicationService
{
    /**
     * Check if a report is a duplicate.
     * Uses three fingerprint levels: exact, target+type, and content hash.
     */
    public function isDuplicate(AbuseReport $report): bool
    {
        $windowHours = config('abusedesk.dedup.window_hours', 24);

        try {
            // Level 1: Exact match — same reporter + target + type
            $exactFp = $this->exactFingerprint($report);
            $existingId = Redis::get("dedup:exact:{$exactFp}");
            if ($existingId) {
                $this->markDuplicate($report, $existingId, 'exact');
                return true;
            }

            // Level 2: Target match — same target IP/domain + type (any reporter)
            // Same target from different reporter — don't skip case creation.
            // Let the case engine handle linking via findExistingCase.
            // This ensures the report gets linked to the case and report_count increments.
            $targetFp = $this->targetFingerprint($report);

            // Level 3: Content hash — detect copy-paste / bot reports
            $contentFp = $this->contentFingerprint($report);
            if ($contentFp) {
                $existingContentId = Redis::get("dedup:content:{$contentFp}");
                if ($existingContentId) {
                    $this->markDuplicate($report, $existingContentId, 'content');
                    return true;
                }
                Redis::setex("dedup:content:{$contentFp}", $windowHours * 3600, $report->id);
            }

            // Store fingerprints
            Redis::setex("dedup:exact:{$exactFp}", $windowHours * 3600, $report->id);
            Redis::setex("dedup:target:{$targetFp}", $windowHours * 3600, $report->id);
        } catch (\Throwable $e) {
            // If Redis is unavailable, fall back to DB check
            return $this->dbFallbackCheck($report, $windowHours);
        }

        return false;
    }

    /**
     * Exact fingerprint: reporter + target + type.
     */
    protected function exactFingerprint(AbuseReport $report): string
    {
        $parts = [
            $report->reporter_id,
            $report->target_ip ?? '',
            $report->target_domain ?? '',
            is_string($report->abuse_type) ? $report->abuse_type : $report->abuse_type->value,
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Target fingerprint: target IP/domain + type (ignores reporter).
     */
    protected function targetFingerprint(AbuseReport $report): string
    {
        $target = $report->target_ip ?? $report->target_domain ?? 'unknown';
        $type = is_string($report->abuse_type) ? $report->abuse_type : $report->abuse_type->value;

        return hash('sha256', "{$target}|{$type}");
    }

    /**
     * Content fingerprint: normalized hash of report body to catch copy-paste / bot reports.
     */
    protected function contentFingerprint(AbuseReport $report): ?string
    {
        $content = $report->raw_payload ?? '';

        if (strlen($content) < 20) {
            return null;
        }

        // Normalize: lowercase, strip whitespace, remove timestamps/IDs
        $normalized = strtolower($content);
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', '', $normalized);
        $normalized = preg_replace('/[a-f0-9-]{36}/', '', $normalized); // UUIDs
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        return hash('sha256', $normalized);
    }

    /**
     * Mark report as duplicate.
     */
    protected function markDuplicate(AbuseReport $report, string $originalId, string $level): void
    {
        $report->update([
            'is_duplicate' => true,
            'duplicate_of' => $originalId,
            'metadata' => array_merge($report->metadata ?? [], [
                'dedup_level' => $level,
                'dedup_matched_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::info('Duplicate report detected', [
            'report_id' => $report->id,
            'duplicate_of' => $originalId,
            'level' => $level,
        ]);
    }

    /**
     * Database fallback when Redis is unavailable.
     */
    protected function dbFallbackCheck(AbuseReport $report, int $windowHours): bool
    {
        $target = $report->target_ip ?? $report->target_domain;
        if (! $target) {
            return false;
        }

        $existing = AbuseReport::where('reporter_id', $report->reporter_id)
            ->where(function ($q) use ($report) {
                if ($report->target_ip) {
                    $q->where('target_ip', $report->target_ip);
                }
                if ($report->target_domain) {
                    $q->orWhere('target_domain', $report->target_domain);
                }
            })
            ->where('abuse_type', $report->abuse_type)
            ->where('id', '!=', $report->id)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->first();

        if ($existing) {
            $this->markDuplicate($report, $existing->id, 'db_fallback');
            return true;
        }

        return false;
    }
}
