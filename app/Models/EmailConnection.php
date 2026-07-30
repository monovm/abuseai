<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailConnection extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'brand_id', 'protocol', 'host', 'port', 'username',
        'password', 'ssl', 'folders', 'is_active', 'auto_import',
        'poll_interval_minutes', 'last_polled_at', 'last_message_count', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ssl' => 'boolean',
            'is_active' => 'boolean',
            'auto_import' => 'boolean',
            'password' => 'encrypted',
            'last_polled_at' => 'datetime',
            'metadata' => 'array',
            'folders' => 'array',
        ];
    }

    /**
     * Folders to poll, with safe defaults. POP3 has no folders so we return
     * a single "INBOX" sentinel that the dispatcher ignores.
     *
     * @return array<int, string>
     */
    public function pollableFolders(): array
    {
        if ($this->protocol !== 'imap') {
            return ['INBOX'];
        }

        $folders = array_values(array_filter(
            array_map('trim', $this->folders ?? []),
            fn ($f) => $f !== '',
        ));

        return $folders ?: ['INBOX'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
