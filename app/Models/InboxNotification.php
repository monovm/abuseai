<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin nav-bar notifications surfaced when a reporter sends a follow-up
 * on an existing case or report. Global (shared across admins) — the
 * abuse team is small enough that per-user read state would only get in
 * the way of "who picks this up?" handoffs.
 */
class InboxNotification extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_CASE_FOLLOWUP = 'case_followup';
    public const TYPE_REPORT_FOLLOWUP = 'report_followup';

    protected $fillable = [
        'type', 'case_id', 'report_id', 'title', 'excerpt', 'meta', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class, 'case_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(AbuseReport::class, 'report_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Resolve the URL the bell-dropdown row should send the analyst to —
     * case detail when we have a case, otherwise the parent case of the
     * report, otherwise the reports queue (the closest landing page).
     */
    public function targetUrl(): string
    {
        if ($this->case_id) {
            return "/admin/cases/{$this->case_id}";
        }
        if ($this->report) {
            return $this->report->case_id
                ? "/admin/cases/{$this->report->case_id}"
                : '/admin/reports';
        }
        return '/admin/reports';
    }
}
