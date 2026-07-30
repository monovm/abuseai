<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'event',
        'actor_id',
        'old_values',
        'new_values',
        'ai_prompt',
        'ai_response',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            // old_values/new_values are jsonb columns and MySQL/Postgres
            // validate them as JSON on insert. An `encrypted:array` cast
            // would write a base64 blob that fails JSON validation —
            // 22032 / 3140 "Invalid JSON text". Leave them as plain
            // arrays; the genuinely sensitive content (full evidence
            // text, AI prompts) lives in ai_prompt/ai_response which
            // are mediumText and accept the encrypted blob fine.
            'old_values' => 'array',
            'new_values' => 'array',
            'ai_prompt' => 'encrypted',
            'ai_response' => 'encrypted',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForEntity($query, string $type, string $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function scopeAiCalls($query)
    {
        return $query->whereNotNull('ai_prompt');
    }
}
