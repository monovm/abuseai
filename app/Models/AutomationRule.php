<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomationRule extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'conditions' => '[]',
        'actions' => '[]',
    ];

    protected $fillable = [
        'name',
        'is_active',
        'trigger_event',
        'conditions',
        'actions',
        'priority',
        'abuse_types',
        'min_score',
        'last_triggered_at',
        'trigger_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
            'abuse_types' => 'array',
            'min_score' => 'decimal:2',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->where('trigger_event', $event);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }
}
