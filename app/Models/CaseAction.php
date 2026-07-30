<?php

namespace App\Models;

use App\Enums\ActionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAction extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'case_id',
        'actor_id',
        'action_type',
        'payload',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class, 'case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
