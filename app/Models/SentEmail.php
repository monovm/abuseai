<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentEmail extends Model
{
    use HasUuids;

    /**
     * Mandrill per-recipient statuses that mean the message was actually
     * handed off for delivery. Anything else ("rejected", "invalid",
     * "failed", "unknown") means it was NOT sent — for "invalid" it never
     * even appears in Mandrill's outbound activity.
     */
    public const ACCEPTED_STATUSES = ['sent', 'queued', 'scheduled'];

    protected $fillable = [
        'case_id', 'brand_id', 'sent_by', 'to_email', 'to_name',
        'from_email', 'from_name', 'subject', 'body_html', 'body_text',
        'message_id', 'status', 'in_reply_to', 'mandrill_response', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'mandrill_response' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Whether Mandrill accepted this message for delivery. A non-accepted
     * row (rejected/invalid/failed) is a send that silently did NOT go out.
     */
    public function wasAccepted(): bool
    {
        return in_array($this->status, self::ACCEPTED_STATUSES, true);
    }

    /**
     * Human-readable reason a send was not accepted, pulled from whatever
     * Mandrill returned (reject_reason on a per-recipient result, or
     * message/name on an API error object). Null when it was accepted.
     */
    public function failureReason(): ?string
    {
        if ($this->wasAccepted()) {
            return null;
        }

        $response = $this->mandrill_response ?? [];

        return $response['reject_reason']
            ?? $response['message']
            ?? $response['name']
            ?? $response['error']
            ?? $this->status;
    }

    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class, 'case_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
