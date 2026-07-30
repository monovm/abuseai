<?php

namespace App\Models;

use App\Enums\AbuseType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'variables' => '[]',
    ];

    protected $fillable = [
        'slug',
        'name',
        'type',
        'abuse_type',
        'subject',
        'body_html',
        'body_text',
        'variables',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'abuse_type' => AbuseType::class,
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type, ?AbuseType $abuseType = null)
    {
        $query->where('type', $type);

        if ($abuseType) {
            $query->where(function ($q) use ($abuseType) {
                $q->where('abuse_type', $abuseType)->orWhereNull('abuse_type');
            });
        }

        return $query;
    }
}
