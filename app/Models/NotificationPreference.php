<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'channel', 'event', 'enabled', 'config',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isEnabled(int $userId, string $channel, string $event): bool
    {
        $pref = static::where('user_id', $userId)
            ->where('channel', $channel)
            ->where('event', $event)
            ->first();

        // Default to enabled if no preference exists
        return $pref ? $pref->enabled : true;
    }

    public static function getAvailableEvents(): array
    {
        return [
            'case_opened' => 'New Case Opened',
            'case_critical' => 'Critical Severity Case',
            'case_actioned' => 'Case Actioned',
            'case_resolved' => 'Case Resolved',
            'ai_flagged' => 'AI Flagged Report',
            'job_failed' => 'Queue Job Failed',
            'csam_detected' => 'CSAM Content Detected',
        ];
    }

    public static function getAvailableChannels(): array
    {
        return [
            'email' => 'Email',
            'slack' => 'Slack',
            'browser' => 'Browser Notification',
        ];
    }
}
