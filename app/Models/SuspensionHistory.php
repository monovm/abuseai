<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspensionHistory extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'suspension_history';

    protected $fillable = [
        'customer_id',
        'case_id',
        'suspended_by',
        'suspension_reason',
        'unsuspended_by',
        'unsuspension_reason',
        'suspended_at',
        'unsuspended_at',
        'whmcs_response',
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'unsuspended_at' => 'datetime',
            'whmcs_response' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class, 'case_id');
    }

    public function suspendedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function unsuspendedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unsuspended_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('unsuspended_at');
    }
}
