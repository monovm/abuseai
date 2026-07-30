<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'ip_addresses' => '[]',
        'services' => '[]',
    ];

    protected $fillable = [
        'external_id',
        'brand_id',
        'email',
        'company',
        'ip_addresses',
        'services',
        'abuse_score',
        'is_suspended',
        'suspended_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ip_addresses' => 'array',
            'services' => 'array',
            'abuse_score' => 'decimal:2',
            'is_suspended' => 'boolean',
            'suspended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(AbuseCase::class);
    }

    public function suspensionHistory(): HasMany
    {
        return $this->hasMany(SuspensionHistory::class);
    }

    public function scopeSuspended($query)
    {
        return $query->where('is_suspended', true);
    }

    public function scopeHighRisk($query)
    {
        return $query->where('abuse_score', '>=', 60);
    }
}
