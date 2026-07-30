<?php

namespace App\Models;

use App\Enums\AbuseType;
use App\Enums\CaseStatus;
use App\Enums\SeverityLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AbuseCase extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'case_number',
        'status',
        'abuse_type',
        'severity_score',
        'severity_level',
        'target_ip',
        'extra_target_ips',
        'external_case_numbers',
        'target_domain',
        'target_service_id',
        'customer_id',
        'brand_id',
        'received_via_brand_id',
        'assignee_id',
        'report_count',
        'first_seen_at',
        'last_seen_at',
        'abuse_occurred_at',
        'actioned_at',
        'resolved_at',
        'closed_at',
        'ai_summary',
        'ai_pattern_flags',
        'sla_deadline',
        'is_legal_hold',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'abuse_type' => AbuseType::class,
            'severity_score' => 'decimal:2',
            'severity_level' => SeverityLevel::class,
            'ai_pattern_flags' => 'array',
            'extra_target_ips' => 'array',
            'external_case_numbers' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'abuse_occurred_at' => 'datetime',
            'actioned_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_deadline' => 'datetime',
            'is_legal_hold' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function receivedViaBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'received_via_brand_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AbuseReport::class, 'case_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(CaseAction::class, 'case_id');
    }

    public function suspensionHistory(): HasMany
    {
        return $this->hasMany(SuspensionHistory::class, 'case_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', CaseStatus::Open);
    }

    public function scopeBySeverity($query, SeverityLevel $level)
    {
        return $query->where('severity_level', $level);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity_level', SeverityLevel::Critical);
    }

    public function scopeByType($query, AbuseType $type)
    {
        return $query->where('abuse_type', $type);
    }

    /**
     * Effective abuse-occurrence time: use the case-level value if set,
     * otherwise fall back to the earliest abuse_occurred_at across linked
     * reports. Covers cases created before AI extraction populated the
     * timestamp on the parent.
     */
    public function getEffectiveAbuseOccurredAtAttribute()
    {
        if ($this->abuse_occurred_at) {
            return $this->abuse_occurred_at;
        }

        return $this->reports()
            ->whereNotNull('abuse_occurred_at')
            ->orderBy('abuse_occurred_at')
            ->first()
            ?->abuse_occurred_at;
    }

    /**
     * Latest moment the abuse was still observed. Used by the
     * "service-predates-abuse" check so that a customer who registered
     * mid-attack still gets bound for the post-registration portion.
     *
     * Sources, in order: the latest report.abuse_occurred_at on this
     * case, the case's last_seen_at (when the most recent linked report
     * came in), and finally the earliest abuse_occurred_at — never
     * returns a moment earlier than effective_abuse_occurred_at.
     */
    public function getEffectiveAbuseLastAtAttribute()
    {
        $latestReport = $this->reports()
            ->whereNotNull('abuse_occurred_at')
            ->orderByDesc('abuse_occurred_at')
            ->first()
            ?->abuse_occurred_at;

        $candidates = array_filter([
            $latestReport,
            $this->last_seen_at,
            $this->effective_abuse_occurred_at,
        ]);

        if (empty($candidates)) {
            return null;
        }

        // Carbon instances are sortable.
        return collect($candidates)->sortDesc()->first();
    }

    public static function generateCaseNumber(): string
    {
        $year = date('Y');
        $prefix = "ABU-{$year}-";

        // Find the highest existing case number for this year, then increment
        $lastCase = static::where('case_number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(case_number, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->first();

        if ($lastCase) {
            $lastSequence = (int) substr($lastCase->case_number, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
    }
}
