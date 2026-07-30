<?php

namespace App\Models;

use App\Enums\AbuseType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbuseReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'case_id',
        'reporter_id',
        'raw_payload',
        'source',
        'abuse_type',
        'target_ip',
        'extra_target_ips',
        'external_case_numbers',
        'target_domain',
        'target_url',
        'evidence',
        'headers',
        'reported_at',
        'abuse_occurred_at',
        'reporter_ip',
        'is_duplicate',
        'duplicate_of',
        'ai_classification',
        'ai_noise_score',
        'enrichment',
        'attachment_paths',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'abuse_type' => AbuseType::class,
            'headers' => 'array',
            'reported_at' => 'datetime',
            'abuse_occurred_at' => 'datetime',
            'is_duplicate' => 'boolean',
            'ai_classification' => 'array',
            'ai_noise_score' => 'decimal:2',
            'enrichment' => 'array',
            'extra_target_ips' => 'array',
            'external_case_numbers' => 'array',
            'attachment_paths' => 'array',
            'metadata' => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class, 'case_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Reporter::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of');
    }

    public function scopeNotDuplicate($query)
    {
        return $query->where('is_duplicate', false);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Processing-pipeline status for this report. Every report is stored on
     * ingest; this tells you what happened to it afterwards. Used by the
     * Reports admin page to verify nothing falls through the cracks.
     *
     * Values:
     *   linked       — attached to a case
     *   duplicate    — marked as a duplicate of another report
     *   noise        — AI pre-screen flagged as non-abuse
     *   no_target    — no target IP or domain extractable
     *   ip_not_ours  — target IP isn't in our (active) inventory
     *   errored      — pipeline threw; metadata.processing_error has details
     *   unprocessed  — none of the above; pipeline probably errored silently
     */
    public function getProcessingStatusAttribute(): string
    {
        if ($this->is_duplicate) {
            return 'duplicate';
        }
        if ($this->case_id) {
            return 'linked';
        }

        $meta = $this->metadata ?? [];

        if (! empty($meta['flagged_as_not_abuse'])) {
            return 'noise';
        }

        if (! empty($meta['processing_error'])) {
            return 'errored';
        }

        $skipped = $meta['skipped_reason'] ?? null;
        if ($skipped === 'no_target_identifier') {
            return 'no_target';
        }
        if ($skipped === 'ip_not_in_inventory' || str_starts_with((string) $skipped, 'ip_exists_but_not_active')) {
            return 'ip_not_ours';
        }

        return 'unprocessed';
    }

    /** SQL filter by processing status. Each branch isolates reports that have
     *  reached the named outcome; rows counted once across all branches. */
    public function scopeWithStatus($query, string $status)
    {
        $notFiltered = fn ($q) => $q->whereNull('metadata->flagged_as_not_abuse')
            ->orWhere('metadata->flagged_as_not_abuse', '!=', true);
        $noSkipReason = fn ($q) => $q->whereNull('metadata->skipped_reason');

        return match ($status) {
            'linked' => $query->whereNotNull('case_id')->where('is_duplicate', false),
            'duplicate' => $query->where('is_duplicate', true),
            'noise' => $query->where('is_duplicate', false)
                ->whereNull('case_id')
                ->where('metadata->flagged_as_not_abuse', true),
            'no_target' => $query->where('is_duplicate', false)
                ->whereNull('case_id')
                ->where($notFiltered)
                ->where('metadata->skipped_reason', 'no_target_identifier'),
            'ip_not_ours' => $query->where('is_duplicate', false)
                ->whereNull('case_id')
                ->where($notFiltered)
                ->where(fn ($q) => $q->where('metadata->skipped_reason', 'ip_not_in_inventory')
                    ->orWhere('metadata->skipped_reason', 'like', 'ip_exists_but_not_active%')),
            'unprocessed' => $query->where('is_duplicate', false)
                ->whereNull('case_id')
                ->where($notFiltered)
                ->where($noSkipReason),
            default => $query,
        };
    }
}
