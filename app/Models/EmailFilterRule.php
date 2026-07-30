<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailFilterRule extends Model
{
    use HasFactory, HasUuids;

    public const FIELD_SUBJECT = 'subject';
    public const FIELD_BODY = 'body';
    public const FIELD_ANY = 'any';

    protected $fillable = [
        'brand_id', 'pattern', 'match_field', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Find the first active drop-rule matching the given subject/body. Rules
     * with a null brand_id apply to every inbox; brand-scoped rules only
     * match when $brandId is the same brand. Match is case-insensitive
     * substring — operators paste keywords or tags, not regex.
     */
    public static function matchDrop(string $subject, string $body, ?string $brandId = null): ?self
    {
        $query = static::query()->where('is_active', true);

        if ($brandId) {
            $query->where(function ($q) use ($brandId) {
                $q->whereNull('brand_id')->orWhere('brand_id', $brandId);
            });
        } else {
            $query->whereNull('brand_id');
        }

        $rules = $query->get();
        if ($rules->isEmpty()) {
            return null;
        }

        $subjectLower = mb_strtolower($subject);
        $bodyLower = mb_strtolower($body);

        foreach ($rules as $rule) {
            $needle = mb_strtolower(trim((string) $rule->pattern));
            if ($needle === '') {
                continue;
            }

            $field = $rule->match_field ?: self::FIELD_ANY;
            $hit = match ($field) {
                self::FIELD_SUBJECT => str_contains($subjectLower, $needle),
                self::FIELD_BODY => str_contains($bodyLower, $needle),
                default => str_contains($subjectLower, $needle) || str_contains($bodyLower, $needle),
            };

            if ($hit) {
                return $rule;
            }
        }

        return null;
    }
}
